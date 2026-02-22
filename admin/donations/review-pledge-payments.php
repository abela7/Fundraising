<?php
// admin/donations/review-pledge-payments.php
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';

// Allow both admin and registrar access
require_login();
$current_user = current_user();
if (!in_array($current_user['role'] ?? '', ['admin', 'registrar'])) {
    header('Location: ' . url_for('index.php'));
    exit;
}
$is_admin = (($current_user['role'] ?? '') === 'admin');
$current_user_id = (int)($current_user['id'] ?? 0);

$db = db();

// Check if table exists
$check = $db->query("SHOW TABLES LIKE 'pledge_payments'");
if ($check->num_rows === 0) {
    die("<div class='alert alert-danger m-4'>Error: Table 'pledge_payments' not found.</div>");
}

// Get filter (status)
$filter = $_GET['filter'] ?? 'pending';
$valid_filters = ['pending', 'confirmed', 'voided', 'all'];
if (!in_array($filter, $valid_filters)) {
    $filter = 'pending';
}

// Pagination settings
$per_page = 15;
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;

// Search
$search = trim($_GET['search'] ?? '');

// Build WHERE clause
$where_conditions = [];
$params = [];
$types = '';

// Registrar scope: only donors assigned to the logged-in registrar.
if (!$is_admin) {
    $where_conditions[] = "d.agent_id = ?";
    $params[] = $current_user_id;
    $types .= 'i';
}

if ($filter === 'pending') {
    $where_conditions[] = "pp.status = 'pending'";
} elseif ($filter === 'confirmed') {
    $where_conditions[] = "pp.status = 'confirmed'";
} elseif ($filter === 'voided') {
    $where_conditions[] = "pp.status = 'voided'";
}

if (!empty($search)) {
    $where_conditions[] = "(d.name LIKE ? OR d.phone LIKE ? OR pp.reference_number LIKE ? OR pl.notes LIKE ?)";
    $search_param = "%{$search}%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'ssss';
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Check if payment_plan_id column exists
$has_plan_col = $db->query("SHOW COLUMNS FROM pledge_payments LIKE 'payment_plan_id'")->num_rows > 0;
$has_user_phone_number_col = $db->query("SHOW COLUMNS FROM users LIKE 'phone_number'")->num_rows > 0;
$assigned_agent_phone_expr = $has_user_phone_number_col
    ? "COALESCE(NULLIF(assigned_agent.phone_number, ''), NULLIF(assigned_agent.phone, ''))"
    : "NULLIF(assigned_agent.phone, '')";

// Get total count for pagination
$count_sql = "
    SELECT COUNT(*) as total
    FROM pledge_payments pp
    LEFT JOIN donors d ON pp.donor_id = d.id
    LEFT JOIN pledges pl ON pp.pledge_id = pl.id
    {$where_clause}
";

if (!empty($params)) {
    $stmt = $db->prepare($count_sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $total_records = $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
} else {
    $total_records = $db->query($count_sql)->fetch_assoc()['total'];
}

$total_pages = ceil($total_records / $per_page);

// Fetch Payment Confirmed Template from database
$sms_template_res = $db->query("SELECT * FROM sms_templates WHERE template_key = 'payment_confirmed' LIMIT 1");
$sms_template = $sms_template_res ? $sms_template_res->fetch_assoc() : null;

// Fetch Fully Paid Confirmation Template
$fully_paid_tpl_res = $db->query("SELECT * FROM sms_templates WHERE template_key = 'fully_paid_confirmation' AND is_active = 1 LIMIT 1");
$fully_paid_template = $fully_paid_tpl_res ? $fully_paid_tpl_res->fetch_assoc() : null;

// Fetch Next Payment Info Templates (usually handled in MessagingHelper or hardcoded if simple)
// For this UI, we'll use the logic already in the JavaScript but we could also fetch sub-templates if needed.

// Fetch payments with pagination
$sql = "
    SELECT 
        pp.*,
        d.name AS donor_name,
        d.phone AS donor_phone,
        d.preferred_language AS donor_language,
        d.total_pledged AS donor_pledge_amount,
        d.total_paid AS donor_total_paid,
        d.balance AS donor_balance,
        d.active_payment_plan_id AS donor_active_plan_id,
        assigned_agent.name AS assigned_agent_name,
        {$assigned_agent_phone_expr} AS assigned_agent_phone,
        assigned_rep.name AS assigned_representative_name,
        assigned_rep.phone AS assigned_representative_phone,
        pl.amount AS pledge_amount,
        pl.created_at AS pledge_date,
        u.name AS processed_by_name,
        approver.name AS approved_by_name,
        voider.name AS voided_by_name" . 
        ($has_plan_col ? ",
        pplan.id AS plan_id,
        pplan.monthly_amount AS plan_monthly_amount,
        pplan.payments_made AS plan_payments_made,
        pplan.total_payments AS plan_total_payments,
        pplan.next_payment_due AS plan_next_payment,
        pplan.status AS plan_status" : "") . "
    FROM pledge_payments pp
    LEFT JOIN donors d ON pp.donor_id = d.id
    LEFT JOIN pledges pl ON pp.pledge_id = pl.id
    LEFT JOIN users assigned_agent ON d.agent_id = assigned_agent.id
    LEFT JOIN church_representatives assigned_rep ON d.representative_id = assigned_rep.id
    LEFT JOIN users u ON pp.processed_by_user_id = u.id
    LEFT JOIN users approver ON pp.approved_by_user_id = approver.id
    LEFT JOIN users voider ON pp.voided_by_user_id = voider.id" . 
    ($has_plan_col ? "
    LEFT JOIN donor_payment_plans pplan ON pp.payment_plan_id = pplan.id" : "") . "
    {$where_clause}
    ORDER BY pp.created_at DESC
    LIMIT {$per_page} OFFSET {$offset}
";

$payments = [];
if (!empty($params)) {
    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $payments[] = $row;
    }
    $stmt->close();
} else {
    $res = $db->query($sql);
    while ($row = $res->fetch_assoc()) {
        $payments[] = $row;
    }
}

// Count statistics
$stats = ['pending' => 0, 'confirmed' => 0, 'voided' => 0];
if ($is_admin) {
    $stats = [
        'pending' => $db->query("SELECT COUNT(*) as c FROM pledge_payments WHERE status='pending'")->fetch_assoc()['c'],
        'confirmed' => $db->query("SELECT COUNT(*) as c FROM pledge_payments WHERE status='confirmed'")->fetch_assoc()['c'],
        'voided' => $db->query("SELECT COUNT(*) as c FROM pledge_payments WHERE status='voided'")->fetch_assoc()['c']
    ];
} else {
    $stat_stmt = $db->prepare("
        SELECT COUNT(*) as c
        FROM pledge_payments pp
        LEFT JOIN donors d ON pp.donor_id = d.id
        WHERE pp.status = ? AND d.agent_id = ?
    ");
    if ($stat_stmt) {
        foreach (['pending', 'confirmed', 'voided'] as $status_key) {
            $stat_stmt->bind_param('si', $status_key, $current_user_id);
            $stat_stmt->execute();
            $row = $stat_stmt->get_result()->fetch_assoc();
            $stats[$status_key] = (int)($row['c'] ?? 0);
        }
        $stat_stmt->close();
    }
}
$stats['all'] = $stats['pending'] + $stats['confirmed'] + $stats['voided'];

// Page titles based on filter
$page_titles = [
    'pending' => 'Pending Payments',
    'confirmed' => 'Approved Payments',
    'voided' => 'Rejected Payments',
    'all' => 'All Payments'
];
$page_title = $page_titles[$filter] ?? 'Review Payments';

// Build pagination URL
function build_url($params) {
    $current = $_GET;
    foreach ($params as $key => $value) {
        if ($value === null) {
            unset($current[$key]);
        } else {
            $current[$key] = $value;
        }
    }
    return '?' . http_build_query($current);
}

function parse_ini_size_to_bytes($value) {
    if ($value === null) return 0;
    $value = trim((string)$value);
    if ($value === '') return 0;

    $unit = strtolower(substr($value, -1));
    $number = (float)$value;

    switch ($unit) {
        case 'g':
            return (int)round($number * 1024 * 1024 * 1024);
        case 'm':
            return (int)round($number * 1024 * 1024);
        case 'k':
            return (int)round($number * 1024);
        default:
            return (int)round((float)$value);
    }
}

$uploadMaxBytes = parse_ini_size_to_bytes(ini_get('upload_max_filesize'));
$postMaxBytes = parse_ini_size_to_bytes(ini_get('post_max_size'));
$serverBodyLimitBytes = 0;
if ($uploadMaxBytes > 0 && $postMaxBytes > 0) {
    $serverBodyLimitBytes = min($uploadMaxBytes, $postMaxBytes);
} elseif ($uploadMaxBytes > 0) {
    $serverBodyLimitBytes = $uploadMaxBytes;
} elseif ($postMaxBytes > 0) {
    $serverBodyLimitBytes = $postMaxBytes;
}

// Keep a safe margin for multipart/form-data overhead and proxy limits.
$clientCertUploadTargetBytes = $serverBodyLimitBytes > 0
    ? (int)floor($serverBodyLimitBytes * 0.70)
    : (int)floor(1.8 * 1024 * 1024);
$clientCertUploadTargetBytes = max(350 * 1024, min($clientCertUploadTargetBytes, (int)floor(1.8 * 1024 * 1024)));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $page_title; ?> - Payment Review</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Montserrat:wght@200;400;600;800;900&display=swap">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        :root {
            --color-pending: #f59e0b;
            --color-approved: #10b981;
            --color-rejected: #ef4444;
            --color-all: #6366f1;
        }
        
        /* Page Header */
        .page-header {
            background: linear-gradient(135deg, #1e3a5f 0%, #0f172a 100%);
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: 1.25rem;
            color: white;
        }
        .page-header h1 {
            font-size: 1.25rem;
            font-weight: 600;
            margin: 0;
        }
        
        /* Stats Row */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0.75rem;
            margin-bottom: 1rem;
        }
        .stat-mini {
            background: white;
            border-radius: 10px;
            padding: 0.875rem;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            border-left: 3px solid;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            color: inherit;
        }
        .stat-mini:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
            color: inherit;
        }
        .stat-mini.active {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
        }
        .stat-mini.pending { border-left-color: var(--color-pending); }
        .stat-mini.approved { border-left-color: var(--color-approved); }
        .stat-mini.rejected { border-left-color: var(--color-rejected); }
        .stat-mini.all { border-left-color: var(--color-all); }
        .stat-mini .stat-count {
            font-size: 1.5rem;
            font-weight: 700;
            line-height: 1;
        }
        .stat-mini.pending .stat-count { color: var(--color-pending); }
        .stat-mini.approved .stat-count { color: var(--color-approved); }
        .stat-mini.rejected .stat-count { color: var(--color-rejected); }
        .stat-mini.all .stat-count { color: var(--color-all); }
        .stat-mini .stat-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
            margin-top: 0.25rem;
            font-weight: 600;
        }
        
        /* Search Bar */
        .search-bar {
            background: white;
            border-radius: 10px;
            padding: 0.875rem;
            margin-bottom: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        .search-bar .form-control {
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            padding: 0.625rem 1rem;
            font-size: 0.875rem;
        }
        .search-bar .form-control:focus {
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }
        .search-bar .btn {
            border-radius: 8px;
            padding: 0.625rem 1rem;
        }
        
        /* Payment Cards */
        .payment-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        .payment-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            overflow: hidden;
            transition: all 0.2s ease;
        }
        .payment-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
        }
        .payment-card.status-pending { border-left: 4px solid var(--color-pending); }
        .payment-card.status-confirmed { border-left: 4px solid var(--color-approved); }
        .payment-card.status-voided { border-left: 4px solid var(--color-rejected); }
        
        .card-header-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 1rem;
            border-bottom: 1px solid #f1f5f9;
        }
        .donor-info h5 {
            font-size: 1rem;
            font-weight: 600;
            margin: 0 0 0.25rem;
            color: #1e293b;
        }
        .donor-info h5 a {
            color: #1e293b;
            text-decoration: none;
            transition: color 0.2s;
            cursor: pointer;
        }
        .donor-info h5 a:hover {
            color: #6366f1;
            text-decoration: underline;
        }
        .donor-info .phone {
            font-size: 0.8rem;
            color: #64748b;
        }
        .amount-badge {
            font-size: 1.125rem;
            font-weight: 700;
            color: var(--color-approved);
            background: #ecfdf5;
            padding: 0.375rem 0.75rem;
            border-radius: 8px;
        }
        
        .card-body-content {
            padding: 1rem;
        }
        .details-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin-bottom: 0.75rem;
        }
        .detail-item {
            display: flex;
            flex-direction: column;
        }
        .detail-item .label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #94a3b8;
            margin-bottom: 0.125rem;
        }
        .detail-item .value {
            font-size: 0.875rem;
            font-weight: 500;
            color: #334155;
        }
        .detail-item .value i {
            margin-right: 0.375rem;
            width: 14px;
            text-align: center;
        }
        
        /* Payment Plan Badge */
        .plan-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: #1e40af;
            padding: 0.375rem 0.625rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-top: 0.5rem;
        }
        
        /* Notes Section */
        .notes-section {
            background: #f8fafc;
            border-radius: 8px;
            padding: 0.625rem 0.75rem;
            margin-top: 0.75rem;
            font-size: 0.8rem;
            color: #475569;
        }
        .notes-section i { margin-right: 0.375rem; color: #94a3b8; }
        
        /* Processing Info */
        .processing-info {
            background: #f8fafc;
            padding: 0.625rem 0.875rem;
            font-size: 0.75rem;
            color: #64748b;
            border-top: 1px solid #f1f5f9;
        }
        .processing-info .info-row {
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
            padding: 0.375rem 0;
            border-bottom: 1px dashed #e2e8f0;
        }
        .processing-info .info-row:last-child { 
            margin-bottom: 0; 
            border-bottom: none;
        }
        .processing-info .info-row i { 
            width: 18px; 
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            font-size: 0.625rem;
            flex-shrink: 0;
            margin-top: 1px;
        }
        .processing-info .info-row i.fa-user-plus {
            background: #e0e7ff;
            color: #4f46e5;
        }
        .processing-info .info-row i.fa-check-circle {
            background: #d1fae5;
            color: #059669;
        }
        .processing-info .info-row i.fa-times-circle {
            background: #fee2e2;
            color: #dc2626;
        }
        .processing-info .info-content {
            flex: 1;
            min-width: 0;
        }
        .processing-info .info-action {
            font-weight: 500;
            color: #334155;
        }
        .processing-info .info-user {
            font-weight: 600;
            color: #1e293b;
        }
        .processing-info .info-time {
            display: block;
            font-size: 0.7rem;
            color: #94a3b8;
            margin-top: 2px;
        }
        .processing-info .text-success .info-action { color: var(--color-approved); }
        .processing-info .text-danger .info-action { color: var(--color-rejected); }
        
        @media (min-width: 576px) {
            .processing-info .info-time {
                display: inline;
                margin-top: 0;
                margin-left: 0.25rem;
            }
            .processing-info .info-time::before {
                content: "•";
                margin-right: 0.25rem;
            }
        }
        
        /* Action Buttons */
        .card-actions {
            display: flex;
            gap: 0.5rem;
            padding: 0.75rem 1rem;
            background: #fafafa;
            border-top: 1px solid #f1f5f9;
        }
        .card-actions .btn {
            flex: 1;
            padding: 0.5rem;
            font-size: 0.8rem;
            font-weight: 500;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.375rem;
        }
        .btn-approve {
            background: var(--color-approved);
            color: white;
            border: none;
        }
        .btn-approve:hover {
            background: #059669;
            color: white;
        }
        .btn-reject {
            background: var(--color-rejected);
            color: white;
            border: none;
        }
        .btn-reject:hover {
            background: #dc2626;
            color: white;
        }
        .btn-undo {
            background: linear-gradient(135deg, #f97316 0%, #ea580c 100%);
            color: white;
            border: none;
        }
        .btn-undo:hover {
            background: linear-gradient(135deg, #ea580c 0%, #c2410c 100%);
            color: white;
        }
        .btn-view {
            background: #e2e8f0;
            color: #475569;
            border: none;
        }
        .btn-view:hover {
            background: #cbd5e1;
            color: #1e293b;
        }
        
        /* Proof Thumbnail */
        .proof-thumb {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            object-fit: cover;
            cursor: pointer;
            border: 2px solid #e2e8f0;
            transition: all 0.2s;
        }
        .proof-thumb:hover {
            border-color: #6366f1;
            transform: scale(1.05);
        }
        .proof-placeholder {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #94a3b8;
        }
        
        /* Status Badge */
        .status-badge-inline {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.5rem;
            border-radius: 6px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-badge-inline.pending {
            background: #fef3c7;
            color: #92400e;
        }
        .status-badge-inline.confirmed {
            background: #d1fae5;
            color: #065f46;
        }
        .status-badge-inline.voided {
            background: #fee2e2;
            color: #991b1b;
        }
        
        /* Void Reason */
        .void-reason {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 8px;
            padding: 0.5rem 0.75rem;
            font-size: 0.8rem;
            color: #991b1b;
            margin-top: 0.5rem;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1.5rem;
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        .empty-state i {
            font-size: 3rem;
            color: #e2e8f0;
            margin-bottom: 1rem;
        }
        .empty-state h5 {
            color: #64748b;
            font-weight: 500;
            margin-bottom: 0.5rem;
        }
        .empty-state p {
            color: #94a3b8;
            font-size: 0.875rem;
            margin: 0;
        }
        
        /* Pagination */
        .pagination-wrapper {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            padding: 1rem;
            margin-top: 0.5rem;
        }
        .pagination-wrapper .page-info {
            font-size: 0.8rem;
            color: #64748b;
            margin: 0 0.5rem;
        }
        .pagination-wrapper .btn {
            padding: 0.5rem 0.875rem;
            font-size: 0.8rem;
            border-radius: 8px;
        }
        .pagination-wrapper .btn-page {
            background: white;
            border: 1px solid #e2e8f0;
            color: #475569;
        }
        .pagination-wrapper .btn-page:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
        }
        .pagination-wrapper .btn-page.active {
            background: #6366f1;
            border-color: #6366f1;
            color: white;
        }
        .pagination-wrapper .btn-page:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        /* Results Info */
        .results-info {
            background: white;
            border-radius: 10px;
            padding: 0.75rem 1rem;
            margin-bottom: 0.75rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .results-info .count {
            font-size: 0.875rem;
            color: #64748b;
        }
        .results-info .count strong {
            color: #1e293b;
        }
        .results-info .btn {
            font-size: 0.75rem;
            padding: 0.375rem 0.75rem;
            border-radius: 6px;
        }
        
        /* Mobile Responsive */
        @media (max-width: 576px) {
            .stats-row {
                grid-template-columns: repeat(2, 1fr);
            }
            .stat-mini {
                padding: 0.75rem 0.5rem;
            }
            .stat-mini .stat-count {
                font-size: 1.25rem;
            }
            .stat-mini .stat-label {
                font-size: 0.65rem;
            }
            .page-header {
                padding: 1rem;
            }
            .page-header h1 {
                font-size: 1.125rem;
            }
            .details-grid {
                grid-template-columns: 1fr;
            }
            .card-actions {
                flex-direction: column;
            }
            .card-actions .btn {
                width: 100%;
            }
            .pagination-wrapper {
                flex-wrap: wrap;
            }
        }
        
        @media (min-width: 768px) {
            .details-grid {
                grid-template-columns: repeat(4, 1fr);
            }
            .card-actions .btn {
                flex: 0 0 auto;
                min-width: 100px;
            }
        }
        
        /* Notification Modal Styles */
        .notification-modal .modal-header {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            border-radius: 12px 12px 0 0;
        }
        .notification-modal .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }
        .notification-modal .modal-content {
            border: none;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        .notification-modal .modal-body {
            padding: 1.5rem;
        }
        .notification-preview {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 1rem;
            font-size: 0.9rem;
            line-height: 1.6;
            white-space: pre-wrap;
            max-height: 300px;
            overflow-y: auto;
        }
        .notification-preview .preview-header {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px dashed #e2e8f0;
        }
        .notification-preview .preview-header i {
            color: #25D366;
            font-size: 1.25rem;
        }
        .notification-preview .preview-header span {
            font-weight: 600;
            color: #334155;
        }
        .notification-info {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        .notification-info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0.75rem;
            background: #f1f5f9;
            border-radius: 8px;
            font-size: 0.875rem;
        }
        .notification-info-item .label {
            color: #64748b;
        }
        .notification-info-item .value {
            font-weight: 600;
            color: #1e293b;
        }
        .notification-actions {
            display: flex;
            gap: 0.75rem;
            margin-top: 1rem;
        }
        .notification-actions .btn {
            flex: 1;
            padding: 0.75rem;
            font-weight: 500;
            border-radius: 8px;
        }
        .btn-send-notification {
            background: linear-gradient(135deg, #25D366 0%, #128C7E 100%);
            color: white;
            border: none;
        }
        .btn-send-notification:hover {
            background: linear-gradient(135deg, #128C7E 0%, #075E54 100%);
            color: white;
        }
        .btn-skip-notification {
            background: #e2e8f0;
            color: #475569;
            border: none;
        }
        .btn-skip-notification:hover {
            background: #cbd5e1;
            color: #1e293b;
        }
        .edit-message-toggle {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8rem;
            color: #6366f1;
            cursor: pointer;
            margin-top: 0.75rem;
        }
        .edit-message-toggle:hover {
            text-decoration: underline;
        }
        .message-textarea {
            width: 100%;
            min-height: 200px;
            padding: 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.875rem;
            line-height: 1.6;
            resize: vertical;
            font-family: inherit;
        }
        .message-textarea:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }
        .sending-spinner {
            display: none;
        }
        .sending-spinner.active {
            display: inline-block;
        }

        /* Certificate Info Banner */
        .cert-toggle-section {
            background: linear-gradient(135deg, #ecfdf5 0%, #f0fdf4 100%);
            border: 1px solid #a7f3d0;
            border-radius: 10px;
            padding: 0.75rem 1rem;
            margin-bottom: 1rem;
        }

        /* ===== Fully Paid Modal ===== */
        .fully-paid-modal .modal-content {
            border: none;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.25);
            overflow: hidden;
        }
        .fully-paid-modal .modal-dialog {
            max-width: 520px;
        }
        .fp-header {
            background: linear-gradient(135deg, #059669 0%, #047857 50%, #065f46 100%);
            padding: 1.5rem 1.75rem;
            color: white;
            position: relative;
            overflow: hidden;
        }
        .fp-header::before {
            content: '';
            position: absolute;
            top: -30px;
            right: -30px;
            width: 120px;
            height: 120px;
            background: rgba(255,255,255,0.08);
            border-radius: 50%;
        }
        .fp-header::after {
            content: '';
            position: absolute;
            bottom: -20px;
            left: 40%;
            width: 80px;
            height: 80px;
            background: rgba(255,255,255,0.05);
            border-radius: 50%;
        }
        .fp-header .btn-close {
            filter: brightness(0) invert(1);
            position: absolute;
            top: 1rem;
            right: 1rem;
            z-index: 2;
        }
        .fp-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255,255,255,0.18);
            backdrop-filter: blur(4px);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin-bottom: 10px;
        }
        .fp-header h5 {
            font-size: 1.15rem;
            font-weight: 700;
            margin: 0;
        }
        .fp-header p {
            margin: 4px 0 0;
            font-size: 0.85rem;
            opacity: 0.85;
        }
        .fp-body {
            padding: 1.5rem 1.75rem;
        }
        .fp-donor-card {
            background: linear-gradient(135deg, #ecfdf5 0%, #f0fdf4 100%);
            border: 1px solid #a7f3d0;
            border-radius: 12px;
            padding: 1rem 1.25rem;
            margin-bottom: 1.25rem;
        }
        .fp-donor-name {
            font-size: 1.1rem;
            font-weight: 700;
            color: #065f46;
            margin-bottom: 2px;
        }
        .fp-donor-phone {
            font-size: 0.825rem;
            color: #6b7280;
        }
        .fp-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 12px;
        }
        .fp-stat {
            background: white;
            border-radius: 8px;
            padding: 8px 12px;
            border: 1px solid #d1fae5;
        }
        .fp-stat-label {
            font-size: 0.7rem;
            font-weight: 600;
            color: #9ca3af;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .fp-stat-value {
            font-size: 1rem;
            font-weight: 700;
            color: #1f2937;
            margin-top: 1px;
        }
        .fp-stat-value.green { color: #059669; }
        .fp-stat-value.blue { color: #2563eb; }
        .fp-progress-complete {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #059669;
            color: white;
            padding: 6px 14px;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-top: 10px;
        }
        .fp-progress-complete i {
            font-size: 0.9rem;
        }
        .fp-section-title {
            font-size: 0.8rem;
            font-weight: 700;
            color: #374151;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .fp-message-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 1rem;
            font-size: 0.85rem;
            line-height: 1.7;
            white-space: pre-wrap;
            max-height: 220px;
            overflow-y: auto;
            color: #374151;
            margin-bottom: 1rem;
        }
        .fp-cert-toggle {
            background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
            border: 1px solid #fbbf24;
            border-radius: 12px;
            padding: 0.875rem 1rem;
            margin-bottom: 1.25rem;
            transition: all 0.2s;
        }
        .fp-cert-toggle.active {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border-color: #f59e0b;
        }
        .fp-cert-toggle .form-check-input:checked {
            background-color: #f59e0b;
            border-color: #f59e0b;
        }
        .fp-cert-toggle .form-check-input {
            width: 2.75em;
            height: 1.35em;
            cursor: pointer;
        }
        .fp-cert-info {
            display: none;
            margin-top: 8px;
            padding: 8px 12px;
            background: rgba(255,255,255,0.7);
            border-radius: 8px;
            font-size: 0.8rem;
            color: #92400e;
        }
        .fp-cert-toggle.active .fp-cert-info {
            display: block;
        }
        .fp-actions {
            display: flex;
            gap: 10px;
            margin-top: 0.25rem;
        }
        .fp-btn-skip {
            flex: 0 0 auto;
            padding: 0.75rem 1.25rem;
            background: #f1f5f9;
            color: #64748b;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-weight: 500;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        .fp-btn-skip:hover {
            background: #e2e8f0;
            color: #334155;
        }
        .fp-btn-send {
            flex: 1;
            padding: 0.75rem 1.25rem;
            background: linear-gradient(135deg, #25D366 0%, #128C7E 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .fp-btn-send:hover:not(:disabled) {
            background: linear-gradient(135deg, #128C7E 0%, #075E54 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(37,211,102,0.3);
        }
        .fp-btn-send:disabled {
            opacity: 0.75;
            cursor: not-allowed;
        }
        .fp-btn-send .spinner-border {
            width: 1rem;
            height: 1rem;
            border-width: 2px;
            display: none;
        }
        .fp-btn-send.loading .spinner-border {
            display: inline-block;
        }
        .fp-btn-send.loading .btn-icon {
            display: none;
        }
        .fp-edit-link {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 0.78rem;
            color: #6366f1;
            cursor: pointer;
            margin-top: -4px;
            margin-bottom: 8px;
        }
        .fp-edit-link:hover { text-decoration: underline; }
        .fp-edit-textarea {
            width: 100%;
            min-height: 180px;
            padding: 0.75rem;
            border: 1px solid #c7d2fe;
            border-radius: 10px;
            font-size: 0.85rem;
            line-height: 1.7;
            resize: vertical;
            font-family: inherit;
            margin-bottom: 1rem;
        }
        .fp-edit-textarea:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99,102,241,0.1);
        }
        .fp-step-indicator {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 0.72rem;
            color: #9ca3af;
            margin-top: 6px;
        }
        .fp-step-indicator .step {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #e5e7eb;
            transition: all 0.3s;
        }
        .fp-step-indicator .step.active {
            background: #25D366;
            width: 18px;
            border-radius: 3px;
        }
        .fp-step-indicator .step.done {
            background: #059669;
        }

    </style>
</head>
<body>
<?= csrf_input() ?>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        <main class="main-content">
            <div class="container-fluid p-2 p-md-4">
                
                <!-- Page Header -->
                <div class="page-header">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h1>
                            <i class="fas fa-receipt me-2"></i><?php echo $page_title; ?>
                        </h1>
                        <a href="record-pledge-payment.php" class="btn btn-light btn-sm">
                            <i class="fas fa-plus me-1"></i><span class="d-none d-sm-inline">Record Payment</span>
                        </a>
                    </div>
                </div>
                
                <!-- Stats Row (Clickable Tab Navigation) -->
                <div class="stats-row">
                    <a href="?filter=pending" class="stat-mini pending <?php echo $filter === 'pending' ? 'active' : ''; ?>">
                        <div class="stat-count"><?php echo $stats['pending']; ?></div>
                        <div class="stat-label"><i class="fas fa-clock"></i> Pending</div>
                    </a>
                    <a href="?filter=confirmed" class="stat-mini approved <?php echo $filter === 'confirmed' ? 'active' : ''; ?>">
                        <div class="stat-count"><?php echo $stats['confirmed']; ?></div>
                        <div class="stat-label"><i class="fas fa-check"></i> Approved</div>
                    </a>
                    <a href="?filter=voided" class="stat-mini rejected <?php echo $filter === 'voided' ? 'active' : ''; ?>">
                        <div class="stat-count"><?php echo $stats['voided']; ?></div>
                        <div class="stat-label"><i class="fas fa-ban"></i> Rejected</div>
                    </a>
                    <a href="?filter=all" class="stat-mini all <?php echo $filter === 'all' ? 'active' : ''; ?>">
                        <div class="stat-count"><?php echo $stats['all']; ?></div>
                        <div class="stat-label"><i class="fas fa-list"></i> All</div>
                    </a>
                </div>
                
                <!-- Search Bar -->
                <div class="search-bar">
                    <form method="get" class="d-flex gap-2">
                        <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                        <div class="flex-grow-1">
                            <input type="text" name="search" class="form-control" 
                                   placeholder="Search by name, phone, payment reference, or pledge reference..." 
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i>
                        </button>
                        <?php if (!empty($search)): ?>
                            <a href="?filter=<?php echo $filter; ?>" class="btn btn-outline-secondary">
                                <i class="fas fa-times"></i>
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
                
                <!-- Results Info -->
                <?php if ($total_records > 0): ?>
                    <div class="results-info">
                        <span class="count">
                            Showing <strong><?php echo min($offset + 1, $total_records); ?>-<?php echo min($offset + $per_page, $total_records); ?></strong> 
                            of <strong><?php echo $total_records; ?></strong> payments
                        </span>
                        <?php if (!empty($search)): ?>
                            <span class="badge bg-primary">
                                Search: "<?php echo htmlspecialchars($search); ?>"
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <!-- Payments List -->
                <?php if (empty($payments)): ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <h5>No payments found</h5>
                        <p>
                            <?php if (!empty($search)): ?>
                                No results match your search "<?php echo htmlspecialchars($search); ?>".
                            <?php else: ?>
                                There are no <?php echo $filter === 'all' ? '' : $filter; ?> payments to display.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <div class="payment-list">
                        <?php foreach ($payments as $p): ?>
                            <div class="payment-card status-<?php echo $p['status']; ?>"
                                 data-payment-id="<?php echo $p['id']; ?>"
                                 data-donor-id="<?php echo $p['donor_id']; ?>"
                                 data-donor-name="<?php echo htmlspecialchars($p['donor_name'] ?? 'Unknown Donor'); ?>"
                                 data-donor-phone="<?php echo htmlspecialchars($p['donor_phone'] ?? ''); ?>"
                                 data-donor-language="<?php echo htmlspecialchars($p['donor_language'] ?? 'en'); ?>"
                                 data-payment-amount="<?php echo number_format((float)$p['amount'], 2); ?>"
                                 data-payment-date="<?php echo $p['created_at'] ? date('l, j F Y', strtotime($p['created_at'])) : ''; ?>"
                                 data-total-pledge="<?php echo number_format((float)($p['donor_pledge_amount'] ?? 0), 2); ?>"
                                 data-total-paid="<?php echo number_format((float)($p['donor_total_paid'] ?? 0), 2); ?>"
                                 data-outstanding-balance="<?php echo number_format((float)($p['donor_balance'] ?? 0), 2); ?>"
                                 data-has-plan="<?php echo (!empty($p['plan_id']) && $p['plan_status'] === 'active') ? '1' : '0'; ?>"
                                 data-next-payment-date="<?php echo (!empty($p['plan_next_payment']) ? date('l, j F Y', strtotime($p['plan_next_payment'])) : ''); ?>"
                                 data-next-payment-amount="<?php echo !empty($p['plan_monthly_amount']) ? number_format((float)$p['plan_monthly_amount'], 2) : ''; ?>"
                                 data-is-fully-paid="<?php
                                    $tPledged = (float)($p['donor_pledge_amount'] ?? 0);
                                    $tBalance = (float)($p['donor_balance'] ?? 0);
                                    echo ($tPledged > 0 && $tBalance <= 0) ? '1' : '0';
                                 ?>"
                                 data-assigned-agent-name="<?php echo htmlspecialchars($p['assigned_agent_name'] ?? ''); ?>"
                                 data-assigned-agent-phone="<?php echo htmlspecialchars($p['assigned_agent_phone'] ?? ''); ?>"
                                 data-assigned-representative-name="<?php echo htmlspecialchars($p['assigned_representative_name'] ?? ''); ?>"
                                 data-assigned-representative-phone="<?php echo htmlspecialchars($p['assigned_representative_phone'] ?? ''); ?>"
                                 data-sqm-value="<?php echo round(max((float)($p['donor_pledge_amount'] ?? 0), (float)($p['donor_total_paid'] ?? 0)) / 400, 2); ?>">
                                <!-- Card Header -->
                                <div class="card-header-row">
                                    <div class="d-flex align-items-start gap-3">
                                        <!-- Proof Thumbnail -->
                                        <?php if ($p['payment_proof']): ?>
                                            <?php 
                                            $ext = strtolower(pathinfo($p['payment_proof'], PATHINFO_EXTENSION));
                                            $is_pdf = ($ext === 'pdf');
                                            ?>
                                            <?php if ($is_pdf): ?>
                                                <a href="../../<?php echo htmlspecialchars($p['payment_proof']); ?>" target="_blank" class="proof-placeholder" style="background: #fee2e2; color: #dc2626;">
                                                    <i class="fas fa-file-pdf"></i>
                                                </a>
                                            <?php else: ?>
                                                <img src="../../<?php echo htmlspecialchars($p['payment_proof']); ?>" 
                                                     alt="Proof" 
                                                     class="proof-thumb"
                                                     onclick="viewProof('../../<?php echo htmlspecialchars($p['payment_proof']); ?>')">
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <div class="proof-placeholder">
                                                <i class="fas fa-receipt"></i>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="donor-info">
                                            <h5>
                                                <?php if (!empty($p['donor_id'])): ?>
                                                    <a href="../donor-management/view-donor.php?id=<?php echo (int)$p['donor_id']; ?>">
                                                        <?php echo htmlspecialchars($p['donor_name'] ?? 'Unknown Donor'); ?>
                                                    </a>
                                                <?php else: ?>
                                                    <?php echo htmlspecialchars($p['donor_name'] ?? 'Unknown Donor'); ?>
                                                <?php endif; ?>
                                            </h5>
                                            <div class="phone">
                                                <i class="fas fa-phone"></i>
                                                <?php echo htmlspecialchars($p['donor_phone'] ?? '-'); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="text-end">
                                        <div class="amount-badge">
                                            £<?php echo number_format((float)$p['amount'], 2); ?>
                                        </div>
                                        <?php if ($filter === 'all'): ?>
                                            <div class="mt-2">
                                                <span class="status-badge-inline <?php echo $p['status']; ?>">
                                                    <?php if ($p['status'] === 'pending'): ?>
                                                        <i class="fas fa-clock"></i> Pending
                                                    <?php elseif ($p['status'] === 'confirmed'): ?>
                                                        <i class="fas fa-check"></i> Approved
                                                    <?php else: ?>
                                                        <i class="fas fa-ban"></i> Rejected
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <!-- Card Body -->
                                <div class="card-body-content">
                                    <div class="details-grid">
                                        <div class="detail-item">
                                            <span class="label">Method</span>
                                            <span class="value">
                                                <?php 
                                                $method_icons = [
                                                    'cash' => 'money-bill-wave',
                                                    'bank_transfer' => 'university',
                                                    'card' => 'credit-card',
                                                    'cheque' => 'file-invoice-dollar',
                                                    'other' => 'hand-holding-usd'
                                                ];
                                                $icon = $method_icons[$p['payment_method']] ?? 'money-bill';
                                                ?>
                                                <i class="fas fa-<?php echo $icon; ?> text-info"></i>
                                                <?php echo ucfirst(str_replace('_', ' ', $p['payment_method'])); ?>
                                            </span>
                                        </div>
                                        <div class="detail-item">
                                            <span class="label">Date</span>
                                            <span class="value">
                                                <i class="far fa-calendar text-secondary"></i>
                                                <?php echo date('d M Y', strtotime($p['payment_date'])); ?>
                                            </span>
                                        </div>
                                        <?php if ($p['reference_number']): ?>
                                        <div class="detail-item">
                                            <span class="label">Reference</span>
                                            <span class="value">
                                                <i class="fas fa-hashtag text-muted"></i>
                                                <?php echo htmlspecialchars($p['reference_number']); ?>
                                            </span>
                                        </div>
                                        <?php endif; ?>
                                        <div class="detail-item">
                                            <span class="label">Pledge</span>
                                            <span class="value">
                                                <i class="fas fa-hand-holding-heart text-primary"></i>
                                                £<?php echo number_format((float)($p['pledge_amount'] ?? 0), 2); ?>
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <?php 
                                    // Show payment plan info
                                    $show_plan_info = false;
                                    $plan_installment = null;
                                    if ($has_plan_col && isset($p['plan_id']) && $p['plan_id']) {
                                        $show_plan_info = true;
                                        $plan_installment = ($p['plan_payments_made'] ?? 0) + 1;
                                    }
                                    ?>
                                    <?php if ($show_plan_info): ?>
                                        <div class="plan-badge">
                                            <i class="fas fa-calendar-check"></i>
                                            Plan Payment: <?php echo $plan_installment; ?> of <?php echo $p['plan_total_payments'] ?? '?'; ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($p['notes']): ?>
                                        <div class="notes-section">
                                            <i class="fas fa-sticky-note"></i>
                                            <?php echo htmlspecialchars($p['notes']); ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($p['status'] === 'voided' && $p['void_reason']): ?>
                                        <div class="void-reason">
                                            <i class="fas fa-exclamation-triangle me-1"></i>
                                            <strong>Rejection Reason:</strong> <?php echo htmlspecialchars($p['void_reason']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Processing Info -->
                                <div class="processing-info">
                                    <div class="info-row">
                                        <i class="fas fa-user-plus"></i>
                                        <div class="info-content">
                                            <span class="info-action">Submitted by</span>
                                            <span class="info-user"><?php echo htmlspecialchars($p['processed_by_name'] ?? 'System'); ?></span>
                                            <span class="info-time"><?php echo date('d M Y, H:i', strtotime($p['created_at'])); ?></span>
                                        </div>
                                    </div>
                                    <?php if ($p['status'] === 'confirmed' && $p['approved_by_name']): ?>
                                        <div class="info-row text-success">
                                            <i class="fas fa-check-circle"></i>
                                            <div class="info-content">
                                                <span class="info-action">Approved by</span>
                                                <span class="info-user"><?php echo htmlspecialchars($p['approved_by_name']); ?></span>
                                                <?php if ($p['approved_at']): ?>
                                                    <span class="info-time"><?php echo date('d M Y, H:i', strtotime($p['approved_at'])); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($p['status'] === 'voided' && $p['voided_by_name']): ?>
                                        <div class="info-row text-danger">
                                            <i class="fas fa-times-circle"></i>
                                            <div class="info-content">
                                                <span class="info-action">Rejected by</span>
                                                <span class="info-user"><?php echo htmlspecialchars($p['voided_by_name']); ?></span>
                                                <?php if ($p['voided_at']): ?>
                                                    <span class="info-time"><?php echo date('d M Y, H:i', strtotime($p['voided_at'])); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Actions -->
                                <div class="card-actions">
                                    <?php if ($p['status'] === 'pending'): ?>
                                        <button class="btn btn-approve" onclick="approvePayment(<?php echo $p['id']; ?>, this)">
                                            <i class="fas fa-check"></i>
                                            <span>Approve</span>
                                        </button>
                                        <button class="btn btn-reject" onclick="voidPayment(<?php echo $p['id']; ?>)">
                                            <i class="fas fa-times"></i>
                                            <span>Reject</span>
                                        </button>
                                    <?php elseif ($p['status'] === 'confirmed'): ?>
                                        <button class="btn btn-approve" onclick="showConfirmationMessage(<?php echo $p['id']; ?>, this)">
                                            <i class="fab fa-whatsapp"></i>
                                            <span>Confirmation</span>
                                        </button>
                                        <button class="btn btn-undo" onclick="undoPayment(<?php echo $p['id']; ?>)">
                                            <i class="fas fa-undo"></i>
                                            <span>Undo</span>
                                        </button>
                                        <a href="../donor-management/view-donor.php?id=<?php echo $p['donor_id']; ?>" class="btn btn-view">
                                            <i class="fas fa-user"></i>
                                            <span>View Donor</span>
                                        </a>
                                    <?php else: ?>
                                        <a href="../donor-management/view-donor.php?id=<?php echo $p['donor_id']; ?>" class="btn btn-view">
                                            <i class="fas fa-user"></i>
                                            <span>View Donor</span>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($p['payment_proof']): ?>
                                        <?php 
                                        $ext = strtolower(pathinfo($p['payment_proof'], PATHINFO_EXTENSION));
                                        $is_pdf = ($ext === 'pdf');
                                        ?>
                                        <?php if ($is_pdf): ?>
                                            <a href="../../<?php echo htmlspecialchars($p['payment_proof']); ?>" target="_blank" class="btn btn-view">
                                                <i class="fas fa-file-pdf"></i>
                                                <span>View PDF</span>
                                            </a>
                                        <?php else: ?>
                                            <button class="btn btn-view" onclick="viewProof('../../<?php echo htmlspecialchars($p['payment_proof']); ?>')">
                                                <i class="fas fa-image"></i>
                                                <span>View Proof</span>
                                            </button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination-wrapper">
                            <a href="<?php echo build_url(['page' => 1]); ?>" 
                               class="btn btn-page <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <i class="fas fa-angle-double-left"></i>
                            </a>
                            <a href="<?php echo build_url(['page' => max(1, $page - 1)]); ?>" 
                               class="btn btn-page <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <i class="fas fa-angle-left"></i>
                            </a>
                            
                            <span class="page-info">
                                Page <?php echo $page; ?> of <?php echo $total_pages; ?>
                            </span>
                            
                            <a href="<?php echo build_url(['page' => min($total_pages, $page + 1)]); ?>" 
                               class="btn btn-page <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                <i class="fas fa-angle-right"></i>
                            </a>
                            <a href="<?php echo build_url(['page' => $total_pages]); ?>" 
                               class="btn btn-page <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                <i class="fas fa-angle-double-right"></i>
                            </a>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                
            </div>
        </main>
    </div>
</div>

<!-- Image Viewer Modal -->
<div class="modal fade" id="proofModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0">
                <h5 class="modal-title">Payment Proof</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center p-2">
                <img id="proofImage" src="" alt="Payment Proof" class="img-fluid rounded" style="max-height: 70vh;">
            </div>
        </div>
    </div>
</div>

<!-- Payment Confirmation Notification Modal -->
<div class="modal fade notification-modal" id="notificationModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-bell me-2"></i>Notify Donor
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted mb-3">
                    Payment approved! Would you like to send a confirmation message to the donor?
                </p>
                
                <!-- Donor Info -->
                <div class="notification-info">
                    <div class="notification-info-item">
                        <span class="label">Donor</span>
                        <span class="value" id="notifyDonorName">-</span>
                    </div>
                    <div class="notification-info-item">
                        <span class="label">Phone</span>
                        <span class="value" id="notifyDonorPhone">-</span>
                    </div>
                    <div class="notification-info-item">
                        <span class="label">Amount Paid</span>
                        <span class="value text-success" id="notifyAmount">£0.00</span>
                    </div>
                    <div class="notification-info-item">
                        <span class="label">Outstanding</span>
                        <span class="value" id="notifyBalance">£0.00</span>
                    </div>
                </div>
                <div id="notifyRoutingNotice" class="alert alert-warning py-2 px-3 mt-2 mb-3">
                    <i class="fas fa-user-shield me-1"></i>
                    Notification will be sent to <strong id="notifyRoutingAgent">-</strong>
                    (<strong id="notifyRoutingPhone">-</strong>)
                </div>
                
                <!-- Certificate Info -->
                <div class="cert-toggle-section">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <i class="fas fa-certificate text-warning" style="font-size: 1.1rem;"></i>
                        <span class="fw-semibold" style="font-size: 0.9rem;">Certificate will be included</span>
                    </div>
                    <small class="text-muted d-block" style="padding-left: 1.75rem;">The donor will receive the text message + a certificate image showing their payment status via WhatsApp.</small>
                </div>

                <!-- Message Preview -->
                <div class="notification-preview" id="messagePreviewContainer">
                    <div class="preview-header">
                        <i class="fab fa-whatsapp"></i>
                        <span>Message Preview</span>
                    </div>
                    <div id="messagePreview"></div>
                </div>
                
                <!-- Edit Toggle -->
                <div class="edit-message-toggle" onclick="toggleMessageEdit()">
                    <i class="fas fa-edit"></i>
                    <span id="editToggleText">Edit message</span>
                </div>
                
                <!-- Editable Message (Hidden by default) -->
                <div id="messageEditContainer" style="display: none; margin-top: 0.75rem;">
                    <textarea id="messageTextarea" class="message-textarea"></textarea>
                </div>
                
                <!-- Actions -->
                <div class="notification-actions">
                    <button type="button" class="btn btn-skip-notification" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Skip
                    </button>
                    <button type="button" class="btn btn-send-notification" onclick="sendNotification()">
                        <span class="sending-spinner" id="sendingSpinner">
                            <i class="fas fa-spinner fa-spin me-1"></i>
                        </span>
                        <i class="fab fa-whatsapp me-1" id="sendIcon"></i>
                        <span id="sendBtnText">Send</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Fully Paid Notification Modal -->
<div class="modal fade fully-paid-modal" id="fullyPaidModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <!-- Header -->
            <div class="fp-header">
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                <div class="fp-badge">
                    <i class="fas fa-check-circle"></i> Fully Paid
                </div>
                <h5><i class="fas fa-trophy me-2"></i>Payment Complete!</h5>
                <p>This donor has completed all pledge payments</p>
            </div>

            <!-- Body -->
            <div class="fp-body">
                <!-- Donor Card -->
                <div class="fp-donor-card">
                    <div class="fp-donor-name" id="fpDonorName">-</div>
                    <div class="fp-donor-phone" id="fpDonorPhone">-</div>
                    <div class="fp-stats">
                        <div class="fp-stat">
                            <div class="fp-stat-label">Total Pledged</div>
                            <div class="fp-stat-value blue" id="fpPledged">-</div>
                        </div>
                        <div class="fp-stat">
                            <div class="fp-stat-label">Total Paid</div>
                            <div class="fp-stat-value green" id="fpPaid">-</div>
                        </div>
                        <div class="fp-stat">
                            <div class="fp-stat-label">This Payment</div>
                            <div class="fp-stat-value" id="fpThisPayment">-</div>
                        </div>
                        <div class="fp-stat">
                            <div class="fp-stat-label">Area Allocation</div>
                            <div class="fp-stat-value green" id="fpArea">-</div>
                        </div>
                    </div>
                    <div class="fp-progress-complete">
                        <i class="fas fa-check-circle"></i>
                        <span>100% Payment Complete</span>
                    </div>
                </div>
                <div id="fpRoutingNotice" class="alert alert-warning py-2 px-3 mb-3">
                    <i class="fas fa-user-shield me-1"></i>
                    Notification will be sent to <strong id="fpRoutingAgent">-</strong>
                    (<strong id="fpRoutingPhone">-</strong>)
                </div>

                <!-- Certificate Info -->
                <div class="fp-cert-toggle active">
                    <div class="d-flex align-items-center gap-2 mb-0">
                        <i class="fas fa-certificate text-warning" style="font-size: 1.1rem;"></i>
                        <span class="fw-semibold" style="font-size: 0.9rem;">Certificate will be included</span>
                    </div>
                    <div class="fp-cert-info" style="display:block;">
                        <i class="fab fa-whatsapp text-success me-1"></i>
                        The donor will receive the text message + certificate image showing 100% completion.
                    </div>
                </div>

                <!-- Message Section -->
                <div class="fp-section-title">
                    <i class="fab fa-whatsapp text-success"></i> WhatsApp Message
                </div>

                <div class="fp-edit-link" id="fpEditLink" onclick="toggleFpEdit()">
                    <i class="fas fa-edit"></i> <span id="fpEditText">Edit message</span>
                </div>

                <div id="fpMessagePreview" class="fp-message-box"></div>
                <textarea id="fpMessageEdit" class="fp-edit-textarea" style="display:none;"></textarea>

                <!-- Actions -->
                <div class="fp-actions">
                    <button type="button" class="fp-btn-skip" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Skip
                    </button>
                    <button type="button" class="fp-btn-send" id="fpSendBtn" onclick="sendFullyPaidNotification()">
                        <span class="spinner-border spinner-border-sm" role="status"></span>
                        <i class="fab fa-whatsapp btn-icon"></i>
                        <span id="fpSendText">Send Message</span>
                    </button>
                </div>
                <div class="fp-step-indicator" id="fpSteps">
                    <span class="step active"></span>
                    <span class="step"></span>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
// Store current notification data
let currentNotificationData = null;
let notificationModal = null;
let isEditMode = false;

// Kesis Birhanu's phone - donors assigned to him get messages routed through him
const KESIS_BIRHANU_PHONE = '07473822244';
const KESIS_BIRHANU_NAME = 'Kesis Birhanu';

// Database Templates (from PHP)
const dbTemplates = <?php echo json_encode($sms_template); ?>;
const dbFullyPaidTemplate = <?php echo json_encode($fully_paid_template); ?>;

function resolveTemplateMode(templateObj) {
    if (!templateObj || typeof templateObj !== 'object') return 'auto';

    const preferred = String(templateObj.preferred_channel || '').toLowerCase();
    if (preferred === 'auto' || preferred === 'sms' || preferred === 'whatsapp') {
        return preferred;
    }

    const platform = String(templateObj.platform || '').toLowerCase();
    if (platform === 'sms' || platform === 'whatsapp') {
        return platform;
    }

    return 'auto';
}

const paymentTemplateMode = resolveTemplateMode(dbTemplates);
const fullyPaidTemplateMode = resolveTemplateMode(dbFullyPaidTemplate);

// Sub-templates for {next_payment_info} variable
const nextPaymentTemplates = {
    en: {
        withPlan: "Your next payment of £{next_payment_amount} is due on {next_payment_date}.",
        withoutPlan: "You can set up a payment plan to manage your remaining balance easily."
    },
    am: {
        withPlan: "ቀጣዩ የ£{next_payment_amount} ክፍያዎ በ{next_payment_date} ነው።",
        withoutPlan: "ቀሪ ሂሳብዎን በቀላሉ ማስተካከል እንዲሁም የክፍያ እቅድ ማዘጋጀት ይችላሉ።"
    },
    ti: {
        withPlan: "ዝቕጽል ክፍሊትካ £{next_payment_amount} ኣብ {next_payment_date} እዩ።",
        withoutPlan: "ዝተረፈ ሒሳብካ ብቐሊሉ ንምምሕዳር መደብ ክፍሊት ከተዳልው ትኽእል።"
    }
};

/**
 * Normalize phone number for comparison
 * Handles formats: 07473822244, +447473822244, 447473822244
 */
function normalizePhoneForComparison(phone) {
    if (!phone) return '';
    let digits = String(phone).replace(/\D/g, '');
    if (digits.startsWith('440') && digits.length >= 13) {
        digits = '0' + digits.substring(3);
    } else if (digits.startsWith('44') && digits.length >= 12) {
        digits = '0' + digits.substring(2);
    }
    return digits;
}

function phonesMatch(phoneA, phoneB) {
    const a = normalizePhoneForComparison(phoneA);
    const b = normalizePhoneForComparison(phoneB);
    if (!a || !b) return false;
    if (a === b) return true;
    return a.replace(/^0/, '') === b.replace(/^0/, '');
}

function isAssignedToKesisBirhanu(data) {
    if (!data) return false;

    return phonesMatch(data.assigned_agent_phone, KESIS_BIRHANU_PHONE);
}

function getRoutingTarget(data) {
    const routedToKesis = isAssignedToKesisBirhanu(data);
    const assignedName = data?.assigned_agent_name || KESIS_BIRHANU_NAME;

    return {
        routedToKesis,
        assignedName,
        destinationPhone: routedToKesis ? KESIS_BIRHANU_PHONE : (data?.donor_phone || '')
    };
}

function renderRoutingNotice(data, noticeId, agentId, phoneId) {
    const noticeEl = document.getElementById(noticeId);
    if (!noticeEl) return;

    const routing = getRoutingTarget(data);
    const recipientName = routing.routedToKesis
        ? routing.assignedName || KESIS_BIRHANU_NAME
        : (data?.donor_name || 'Donor');
    const agentEl = document.getElementById(agentId);
    const phoneEl = document.getElementById(phoneId);
    if (agentEl) agentEl.textContent = recipientName;
    if (phoneEl) phoneEl.textContent = routing.destinationPhone || '';
    noticeEl.classList.remove('d-none');
}

function viewProof(src) {
    document.getElementById('proofImage').src = src;
    new bootstrap.Modal(document.getElementById('proofModal')).show();
}

function approvePayment(id, btn) {
    // Disable the button to prevent double-clicks
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Approving...';
    
    fetch('approve-pledge-payment.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({id: id})
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            // Check if we have notification data
            if (res.notification_data) {
                const data = res.notification_data;
                const routing = getRoutingTarget(data);

                if (!routing.destinationPhone) {
                    alert('Payment approved, but no valid recipient phone was found.');
                    location.reload();
                    return;
                }

                // Check if donor is assigned to Kesis Birhanu — ALWAYS route through him
                if (routing.routedToKesis) {
                    // Route to Kesis Birhanu (both ongoing and fully paid)
                    sendToKesisBirhanu(data, btn);
                } else {
                    // Not assigned to Kesis Birhanu: send directly to donor
                    showNotificationModal(data);
                }
            } else {
                // No phone number, just reload
                location.reload();
            }
        } else {
            alert('Error: ' + res.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check"></i> <span>Approve</span>';
        }
    })
    .catch(err => {
        alert('Network error. Please try again.');
        console.error(err);
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check"></i> <span>Approve</span>';
    });
}

/**
 * Send notification to Kesis Birhanu for donors assigned to him.
 * Handles both ongoing and fully paid cases.
 * - Ongoing: text message + progress certificate via WhatsApp
 * - Fully paid: text message + final certificate via WhatsApp
 * Message content is addressed to the donor, but sent to agent's phone.
 */
async function sendToKesisBirhanu(data, btn) {
    const isFullyPaid = !!data.is_fully_paid;
    const routing = getRoutingTarget(data);

    // Pick the right template based on fully paid status
    const templateMode = isFullyPaid ? fullyPaidTemplateMode : paymentTemplateMode;
    const lang = templateMode === 'sms' ? 'en' : 'am';
    let message = '';

    if (isFullyPaid) {
        // Build message from fully_paid_confirmation template
        if (dbFullyPaidTemplate) {
            if (lang === 'am' && dbFullyPaidTemplate.message_am) message = dbFullyPaidTemplate.message_am;
            else if (lang === 'ti' && dbFullyPaidTemplate.message_ti) message = dbFullyPaidTemplate.message_ti;
            else if (dbFullyPaidTemplate.message_en) message = dbFullyPaidTemplate.message_en;
            if (!message && dbFullyPaidTemplate.message_am) message = dbFullyPaidTemplate.message_am;
        }
        if (!message) {
            if (lang === 'am') {
                message = 'ሰላም ጤና ይስጥልን ወድ {donor_name}፣\n\nሙሉ ቃል ኪዳን ክፍያዎን ስለጨረሱ እናመሰግናለን።\n\nበዛሬው ዕለት ({date}) የተቀበልነው ክፍያ: £{payment_amount}\n\nየቃል ኪዳንዎ ማጠቃለያ፡\n→ ጠቅላላ ቃል ኪዳን: {total_pledged_sqm} ካሬ ሜትር, £{total_pledged}\n→ ጠቅላላ የከፈሉት: £{total_paid}\n→ ቀሪ: £{remaining}\n\nአምላከ ተክለሃይማኖት በሰጡት አብዝቶ ይስጥልን።\n\n- ሊቨርፑል አቡነ ተክለሃይማኖት ቤተ ክርስቲያን';
            } else {
                message = 'Dear {donor_name},\n\nThank you for completing your full pledge payment.\n\nPayment received on {date}: £{payment_amount}\n\nPledge summary:\n→ Total pledge: {total_pledged_sqm} m², £{total_pledged}\n→ Total paid: £{total_paid}\n→ Remaining: £{remaining}\n\n- Liverpool Abune Teklehaymanot Church';
            }
        }
        message = message
            .replace(/\{donor_name\}/g, data.donor_name)
            .replace(/\{date\}/g, data.payment_date)
            .replace(/\{payment_amount\}/g, data.payment_amount)
            .replace(/\{total_pledged_sqm\}/g, (data.sqm_value || '0'))
            .replace(/\{total_pledged\}/g, data.total_pledge)
            .replace(/\{total_paid\}/g, data.total_paid)
            .replace(/\{remaining\}/g, '0.00');
    } else {
        // Build message from payment_confirmed template
        let baseTemplate = '';
        if (dbTemplates) {
            if (lang === 'am') baseTemplate = dbTemplates.message_am;
            else if (lang === 'ti') baseTemplate = dbTemplates.message_ti;
            else baseTemplate = dbTemplates.message_en;
        }
        if (!baseTemplate) {
            if (lang === 'am') {
                baseTemplate = "ሰላም ጤና ይስጥልን ወድ {name}፣\n\nበዛሬው ዕለት የ£{amount} ክፍያዎን ተቀብለናል።\n\nቀሪ ሂሳብዎ: £{outstanding_balance}\n\n{next_payment_info}\n\nአምላከ ተክለሃይማኖት በሰጡት አብዝቶ ይስጥልን።";
            } else {
                baseTemplate = "Dear {name},\n\nThank you. We received your payment of £{amount} on {payment_date}.\n\nOutstanding balance: £{outstanding_balance}\n\n{next_payment_info}\n\n- Liverpool Abune Teklehaymanot Church";
            }
        }
        const nextTemplates = nextPaymentTemplates[lang] || nextPaymentTemplates['en'];
        const nextInfoTemplate = data.has_plan ? nextTemplates.withPlan : nextTemplates.withoutPlan;
        message = baseTemplate.replace(/{next_payment_info}/g, nextInfoTemplate);
        message = message
            .replace(/{name}/g, data.donor_name)
            .replace(/{amount}/g, data.payment_amount)
            .replace(/{payment_date}/g, data.payment_date)
            .replace(/{total_pledge}/g, data.total_pledge)
            .replace(/{total_paid}/g, data.total_paid)
            .replace(/{outstanding_balance}/g, data.outstanding_balance);
        if (data.has_plan) {
            message = message
                .replace(/{next_payment_amount}/g, data.next_payment_amount || data.payment_amount)
                .replace(/{next_payment_date}/g, data.next_payment_date || 'TBD');
        }
    }

    // Handle \n literals from database templates
    message = message.replace(/\\n/g, '\n');

    // Update button to show sending status
    const agentLabel = routing.assignedName || KESIS_BIRHANU_NAME;
    btn.innerHTML = '<i class="fas fa-info-circle"></i> Assigned to ' + agentLabel;
    await new Promise(resolve => setTimeout(resolve, 450));
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending to ' + agentLabel + '...';

    try {
        // Capture the appropriate certificate
        const certType = isFullyPaid ? 'completed' : 'progress';
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating certificate...';

        const d = await fetchDonorCertificateData(data.donor_id);

        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Capturing certificate...';
        const blob = await captureCertificateFromDonorView(data.donor_id, certType);

        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending to ' + agentLabel + '...';

        // Send certificate + message to Kesis Birhanu's phone
        const formData = new FormData();
        const filePrefix = isFullyPaid ? 'certificate_final' : 'certificate';
        formData.append('certificate', blob, getCertificateFileName(filePrefix, d.name, blob));
        formData.append('phone', routing.destinationPhone);
        formData.append('donor_id', data.donor_id);
        formData.append('donor_name', d.name);
        formData.append('sqm_value', d.sqm_value);
        formData.append('total_paid', d.currency + parseFloat(d.total_paid).toFixed(2));
        formData.append('message', message);

        const csrfMeta = document.querySelector('meta[name="csrf-token"]');
        const csrfInput = document.querySelector('input[name="csrf_token"]');
        const csrfToken = csrfMeta ? csrfMeta.content : (csrfInput ? csrfInput.value : '');
        formData.append('csrf_token', csrfToken);

        const certRes = await fetch('../donor-management/api/send-certificate-whatsapp.php', {
            method: 'POST',
            body: formData
        });
        const certResult = await certRes.json();

        if (certResult.success) {
            btn.innerHTML = '<i class="fas fa-check"></i> Sent to ' + agentLabel;
            btn.classList.remove('btn-approve');
            btn.classList.add('btn-success');
            setTimeout(() => location.reload(), 1500);
        } else {
            throw new Error(certResult.error || 'Failed to send');
        }
    } catch (err) {
        console.error('Error sending to ' + agentLabel + ':', err);
        alert('Payment approved, but failed to send to ' + agentLabel + ': ' + err.message);
        location.reload();
    }
}

/**
 * Show confirmation message for already-confirmed payments
 * Retrieves payment data from the card's data attributes
 */
function showConfirmationMessage(paymentId, btn = null) {
    // Find the payment card element
    const card = document.querySelector(`[data-payment-id="${paymentId}"]`);
    if (!card) {
        alert('Payment data not found');
        return;
    }
    
    // Extract data from card attributes
    const data = {
        donor_id: parseInt(card.dataset.donorId),
        donor_name: card.dataset.donorName,
        donor_phone: card.dataset.donorPhone,
        donor_language: card.dataset.donorLanguage || 'en',
        payment_amount: card.dataset.paymentAmount,
        payment_date: card.dataset.paymentDate,
        total_pledge: card.dataset.totalPledge,
        total_paid: card.dataset.totalPaid,
        outstanding_balance: card.dataset.outstandingBalance,
        has_plan: card.dataset.hasPlan === '1',
        next_payment_date: card.dataset.nextPaymentDate || null,
        next_payment_amount: card.dataset.nextPaymentAmount || null,
        is_fully_paid: card.dataset.isFullyPaid === '1',
        sqm_value: parseFloat(card.dataset.sqmValue || '0'),
        assigned_agent_name: card.dataset.assignedAgentName || '',
        assigned_agent_phone: card.dataset.assignedAgentPhone || '',
    };

    const routing = getRoutingTarget(data);

    // Validate required fields
    if (!routing.destinationPhone) {
        alert('No phone number available for this donor');
        return;
    }

    if (routing.routedToKesis && btn) {
        const confirmed = confirm(
            'This donor is assigned to ' + routing.assignedName + '.\n' +
            'Notification and certificate will be sent to ' +
            routing.assignedName + ' (' + routing.destinationPhone + ').\n\n' +
            'Continue?'
        );

        if (!confirmed) {
            return;
        }

        sendToKesisBirhanu(data, btn);
        return;
    }

    // Show the notification modal
    showNotificationModal(data);
}

function showNotificationModal(data) {
    currentNotificationData = data;
    isEditMode = false;

    // Check if donor is fully paid - show special modal
    if (data.is_fully_paid) {
        showFullyPaidModal(data);
        return;
    }

    // Update modal fields
    document.getElementById('notifyDonorName').textContent = data.donor_name;
    document.getElementById('notifyDonorPhone').textContent = data.donor_phone;
    document.getElementById('notifyAmount').textContent = '£' + data.payment_amount;
    document.getElementById('notifyBalance').textContent = '£' + data.outstanding_balance;
    renderRoutingNotice(data, 'notifyRoutingNotice', 'notifyRoutingAgent', 'notifyRoutingPhone');
    
    // Respect template mode: sms => English, auto/whatsapp => Amharic.
    const lang = paymentTemplateMode === 'sms' ? 'en' : 'am';
    let baseTemplate = '';
    
    if (dbTemplates) {
        if (lang === 'am') baseTemplate = dbTemplates.message_am;
        else if (lang === 'ti') baseTemplate = dbTemplates.message_ti;
        else baseTemplate = dbTemplates.message_en;
    }
    
    // Fallback if no template found.
    if (!baseTemplate) {
        if (lang === 'am') {
            baseTemplate = "ሰላም ጤና ይስጥልን ወድ {name}፣\n\nበዛሬው ዕለት የ£{amount} ክፍያዎን ተቀብለናል።\n\nቀሪ ሂሳብዎ: £{outstanding_balance}\n\n{next_payment_info}\n\nአምላከ ተክለሃይማኖት በሰጡት አብዝቶ ይስጥልን።";
        } else {
            baseTemplate = "Dear {name},\n\nThank you. We received your payment of £{amount} on {payment_date}.\n\nOutstanding balance: £{outstanding_balance}\n\n{next_payment_info}\n\n- Liverpool Abune Teklehaymanot Church";
        }
    }

    // Get the appropriate next_payment_info sub-template
    const nextTemplates = nextPaymentTemplates[lang] || nextPaymentTemplates['en'];
    const nextInfoTemplate = data.has_plan ? nextTemplates.withPlan : nextTemplates.withoutPlan;
    
    // Replace {next_payment_info} first
    let message = baseTemplate.replace(/{next_payment_info}/g, nextInfoTemplate);
    
    // Replace other variables
    message = message
        .replace(/{name}/g, data.donor_name)
        .replace(/{amount}/g, data.payment_amount)
        .replace(/{payment_date}/g, data.payment_date)
        .replace(/{total_pledge}/g, data.total_pledge)
        .replace(/{total_paid}/g, data.total_paid)
        .replace(/{outstanding_balance}/g, data.outstanding_balance);
    
    if (data.has_plan) {
        message = message
            .replace(/{next_payment_amount}/g, data.next_payment_amount || data.payment_amount)
            .replace(/{next_payment_date}/g, data.next_payment_date || 'TBD');
    }
    
    // Store the message
    currentNotificationData.message = message;
    currentNotificationData.template_mode = paymentTemplateMode;
    
    // Update preview
    document.getElementById('messagePreview').textContent = message;
    document.getElementById('messageTextarea').value = message;
    
    // Reset edit state
    document.getElementById('messagePreviewContainer').style.display = '';
    document.getElementById('messageEditContainer').style.display = 'none';
    document.getElementById('editToggleText').textContent = 'Edit message';
    
    // Reset send button
    document.getElementById('sendingSpinner').classList.remove('active');
    document.getElementById('sendIcon').style.display = '';
    document.getElementById('sendBtnText').textContent = 'Send';
    document.querySelector('.btn-send-notification').disabled = false;
    
    // Show modal
    if (!notificationModal) {
        notificationModal = new bootstrap.Modal(document.getElementById('notificationModal'));
    }
    
    // When modal is closed, reload the page
    document.getElementById('notificationModal').addEventListener('hidden.bs.modal', function() {
        location.reload();
    }, { once: true });
    
    notificationModal.show();
}

function toggleMessageEdit() {
    isEditMode = !isEditMode;
    
    if (isEditMode) {
        document.getElementById('messagePreviewContainer').style.display = 'none';
        document.getElementById('messageEditContainer').style.display = '';
        document.getElementById('editToggleText').textContent = 'Preview message';
        document.getElementById('messageTextarea').focus();
    } else {
        // Update preview with edited message
        const editedMessage = document.getElementById('messageTextarea').value;
        document.getElementById('messagePreview').textContent = editedMessage;
        currentNotificationData.message = editedMessage;
        
        document.getElementById('messagePreviewContainer').style.display = '';
        document.getElementById('messageEditContainer').style.display = 'none';
        document.getElementById('editToggleText').textContent = 'Edit message';
    }
}

function sendNotification() {
    if (!currentNotificationData) return;
    
    // Get the current message (might be edited)
    const message = isEditMode 
        ? document.getElementById('messageTextarea').value 
        : currentNotificationData.message;
    
    // Show loading state
    const btn = document.querySelector('.btn-send-notification');
    btn.disabled = true;
    document.getElementById('sendingSpinner').classList.add('active');
    document.getElementById('sendIcon').style.display = 'none';
    document.getElementById('sendBtnText').textContent = 'Sending...';
    
    // Send the notification
    fetch('send-payment-notification.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            donor_id: currentNotificationData.donor_id,
            phone: currentNotificationData.donor_phone,
            message: message,
            language: paymentTemplateMode === 'sms' ? 'en' : 'am',
            template_mode: currentNotificationData.template_mode || paymentTemplateMode,
            template_key: 'payment_confirmed'
        })
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            // Show success
            document.getElementById('sendingSpinner').classList.remove('active');
            document.getElementById('sendIcon').className = 'fas fa-check me-1';
            document.getElementById('sendIcon').style.display = '';
            document.getElementById('sendBtnText').textContent = 'Sent!';
            
            // Close modal and reload after short delay
            setTimeout(() => {
                notificationModal.hide();
            }, 1000);
        } else {
            alert('Failed to send notification: ' + (res.error || 'Unknown error'));
            btn.disabled = false;
            document.getElementById('sendingSpinner').classList.remove('active');
            document.getElementById('sendIcon').style.display = '';
            document.getElementById('sendBtnText').textContent = 'Retry';
        }
    })
    .catch(err => {
        console.error(err);
        alert('Network error. The message may or may not have been sent.');
        btn.disabled = false;
        document.getElementById('sendingSpinner').classList.remove('active');
        document.getElementById('sendIcon').style.display = '';
        document.getElementById('sendBtnText').textContent = 'Retry';
    });
}

function voidPayment(id) {
    const reason = prompt('Why are you rejecting this payment?\n\n(This will be logged in the audit trail)');
    if (!reason) return;
    
    fetch('void-pledge-payment.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({id: id, reason: reason})
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            alert('Payment rejected.');
            location.reload();
        } else {
            alert('Error: ' + res.message);
        }
    })
    .catch(err => {
        alert('Network error. Please try again.');
        console.error(err);
    });
}

function undoPayment(id) {
    if (!confirm('Undo this approved payment?\n\nWARNING: This will:\n• Reverse donor balance updates\n• Mark payment as voided\n• Update financial totals\n\nOnly do this if the approval was a mistake!')) return;
    
    const reason = prompt('Why are you undoing this payment?\n\n(Required for audit trail)');
    if (!reason) return;
    
    fetch('undo-pledge-payment.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({id: id, reason: reason})
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            alert('✓ Payment undone successfully.');
            location.reload();
        } else {
            alert('Error: ' + res.message);
        }
    })
    .catch(err => {
        alert('Network error. Please try again.');
        console.error(err);
    });
}
</script>

<!-- Hidden Certificate Render Area (offscreen, used by html2canvas) -->
<div id="certRenderArea" style="position:absolute;left:-9999px;top:-9999px;z-index:-1;">
    <div id="certCaptureWrapper" style="width:1200px;height:870px;">
        <!-- Certificate (750px) -->
        <div id="certRender" style="position:relative;width:1200px;height:750px;background-image:url('../../assets/images/cert-bg.png');background-size:cover;background-position:center;color:white;font-family:'Montserrat',sans-serif;">
            <div style="position:absolute;top:0;right:0;width:500px;height:100%;pointer-events:none;overflow:hidden;">
                <div style="position:absolute;top:50%;right:-50px;transform:translateY(-50%);width:450px;height:450px;border-radius:50%;background-image:url('../../assets/images/new-church.png');background-size:cover;background-position:center;opacity:0.15;filter:saturate(0.6) brightness(1.1);"></div>
            </div>
            <div style="position:absolute;top:25px;left:0;right:0;text-align:center;z-index:1;">
                <div style="font-size:41px;font-weight:200;color:#ffcc33;font-family:'Nyala','Segoe UI Ethiopic',serif;padding:0 60px;">"የምሠራውም ቤት እጅግ ታላቅና ድንቅ ይሆናልና ብዙ እንጨት ያዘጋጁልኝ ዘንድ እነሆ ባሪያዎቼ ከባሪያዎችህ ጋር ይሆናሉ፡፡" ፪ ዜና ፪፡፱</div>
                <div style="font-size:48px;font-weight:600;letter-spacing:1px;text-transform:uppercase;margin-top:10px;padding:0 30px;">LIVERPOOL ABUNE TEKLEHAYMANOT EOTC</div>
            </div>
            <div style="position:absolute;top:200px;left:0;right:0;text-align:center;z-index:1;">
                <div style="font-size:135px;font-weight:900;line-height:1;font-family:'Nyala','Segoe UI Ethiopic',sans-serif;text-shadow:0 5px 15px rgba(0,0,0,0.2);padding-top:45px;">ይህ ታሪኬ ነው</div>
                <div style="font-size:120px;font-weight:900;line-height:1;letter-spacing:-3px;margin-top:5px;text-shadow:0 5px 15px rgba(0,0,0,0.2);">It is My History</div>
            </div>
            <div style="position:absolute;bottom:40px;left:50px;right:50px;display:flex;justify-content:space-between;align-items:flex-end;z-index:1;">
                <div style="display:flex;align-items:center;gap:30px;">
                    <div style="width:160px;height:160px;background:white;padding:10px;flex-shrink:0;">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=http://donate.abuneteklehaymanot.org/" alt="QR" style="width:100%;height:100%;display:block;">
                    </div>
                    <div style="font-size:38px;font-weight:800;line-height:1.3;max-width:650px;">
                        <div style="display:flex;gap:15px;"><span style="color:#fff;white-space:nowrap;">Name -</span><span id="certDonorName" style="color:#ffcc33;word-break:break-word;"></span></div>
                        <div style="display:flex;gap:15px;margin-top:15px;"><span style="color:#fff;white-space:nowrap;">Pledge -</span><span id="certContribution" style="color:#ffcc33;word-break:break-word;"></span></div>
                    </div>
                </div>
                <div style="display:flex;flex-direction:column;align-items:center;gap:15px;">
                    <div style="width:280px;height:100px;background:#ffffff;border-radius:50px;box-shadow:0 4px 15px rgba(0,0,0,0.2);display:flex;align-items:center;justify-content:center;">
                        <span id="certSqmPill" style="font-size:48px;font-weight:900;color:#333;text-shadow:none;"></span>
                    </div>
                    <div id="certRefBottom" style="font-size:20px;font-weight:600;color:#fff;letter-spacing:2px;font-family:'Courier New',monospace;text-align:right;"></div>
                </div>
            </div>
        </div>
        <!-- Stats Strip (120px) -->
        <div id="certStatsStrip" style="width:1200px;height:120px;background:#ffffff;padding:16px 50px 12px;box-sizing:border-box;font-family:'Montserrat',sans-serif;">
            <div id="certStatsRow" style="display:flex;justify-content:space-around;align-items:center;">
                <div style="text-align:center;flex:1;">
                    <div style="font-size:16px;font-weight:600;color:#999;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:2px;">Ref</div>
                    <div id="certStatRef" style="font-size:30px;font-weight:800;color:#333;font-family:'Courier New',monospace;letter-spacing:2px;"></div>
                </div>
                <div style="width:1px;height:36px;background:#e0e0e0;flex-shrink:0;"></div>
                <div style="text-align:center;flex:1;">
                    <div style="font-size:16px;font-weight:600;color:#999;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:2px;">Pledged</div>
                    <div id="certStatPledged" style="font-size:30px;font-weight:800;color:#1a73e8;"></div>
                </div>
                <div style="width:1px;height:36px;background:#e0e0e0;flex-shrink:0;"></div>
                <div style="text-align:center;flex:1;">
                    <div style="font-size:16px;font-weight:600;color:#999;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:2px;">Paid</div>
                    <div id="certStatPaid" style="font-size:30px;font-weight:800;"></div>
                </div>
                <div style="width:1px;height:36px;background:#e0e0e0;flex-shrink:0;"></div>
                <div style="text-align:center;flex:1;">
                    <div style="font-size:16px;font-weight:600;color:#999;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:2px;">Area</div>
                    <div id="certStatArea" style="font-size:30px;font-weight:800;color:#2e7d32;"></div>
                </div>
            </div>
            <div id="certProgressWrap" style="margin-top:10px;display:none;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;">
                    <span style="font-size:14px;font-weight:600;color:#999;">Payment Progress</span>
                    <span id="certProgressPct" style="font-size:14px;font-weight:700;color:#333;"></span>
                </div>
                <div style="width:100%;height:10px;background:#e8e8e8;border-radius:5px;overflow:hidden;">
                    <div id="certProgressFill" style="height:100%;border-radius:5px;"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
async function ensureHtml2CanvasLoaded() {
    if (typeof html2canvas !== 'undefined') return;

    await new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js';
        script.onload = resolve;
        script.onerror = () => reject(new Error('Failed to load html2canvas'));
        document.head.appendChild(script);
    });
}

async function fetchDonorCertificateData(donorId) {
    const dataRes = await fetch(`api/get-donor-certificate-data.php?donor_id=${donorId}`);
    const dataJson = await dataRes.json();
    if (!dataJson.success) {
        throw new Error(dataJson.error || 'Failed to load certificate data');
    }
    return dataJson.donor;
}

// Derived from server limits with safety margin; capped for consistent quality.
const CERT_UPLOAD_LIMIT_BYTES = <?php echo (int)$clientCertUploadTargetBytes; ?>;

function canvasToBlobAsync(canvas, type, quality) {
    return new Promise((resolve, reject) => {
        canvas.toBlob((blob) => {
            if (!blob) {
                reject(new Error('Failed to generate certificate image'));
                return;
            }
            resolve(blob);
        }, type, quality);
    });
}

async function optimizeCertificateBlob(canvas) {
    let smallestBlob = null;

    const keepSmallest = (blob) => {
        if (!smallestBlob || blob.size < smallestBlob.size) smallestBlob = blob;
    };

    // Keep PNG when possible for best text clarity.
    const pngBlob = await canvasToBlobAsync(canvas, 'image/png');
    keepSmallest(pngBlob);
    if (pngBlob.size <= CERT_UPLOAD_LIMIT_BYTES) return pngBlob;

    // Fall back to JPEG with progressive quality reduction to stay under API limit.
    const jpegQualities = [0.92, 0.86, 0.8, 0.74];
    for (const quality of jpegQualities) {
        const jpegBlob = await canvasToBlobAsync(canvas, 'image/jpeg', quality);
        keepSmallest(jpegBlob);
        if (jpegBlob.size <= CERT_UPLOAD_LIMIT_BYTES) return jpegBlob;
    }

    // Last resort: downscale and compress harder.
    const reducedWidth = 1000;
    if (canvas.width > reducedWidth) {
        const ratio = reducedWidth / canvas.width;
        const reducedCanvas = document.createElement('canvas');
        reducedCanvas.width = reducedWidth;
        reducedCanvas.height = Math.round(canvas.height * ratio);
        const ctx = reducedCanvas.getContext('2d');
        if (ctx) {
            ctx.drawImage(canvas, 0, 0, reducedCanvas.width, reducedCanvas.height);
            const reducedQualities = [0.82, 0.75, 0.68];
            for (const quality of reducedQualities) {
                const reducedBlob = await canvasToBlobAsync(reducedCanvas, 'image/jpeg', quality);
                keepSmallest(reducedBlob);
                if (reducedBlob.size <= CERT_UPLOAD_LIMIT_BYTES) return reducedBlob;
            }
        }
    }

    const sizeMb = smallestBlob ? (smallestBlob.size / (1024 * 1024)).toFixed(2) : 'unknown';
    throw new Error('Certificate image too large (' + sizeMb + 'MB) after optimization');
}

function getCertificateFileName(prefix, donorName, blob) {
    const safeName = (donorName || 'donor').replace(/[^a-z0-9]/gi, '_');
    let ext = 'png';
    if (blob && blob.type === 'image/jpeg') ext = 'jpg';
    else if (blob && blob.type === 'image/webp') ext = 'webp';
    return `${prefix}_${safeName}.${ext}`;
}

/**
 * Capture a certificate from the donor view page via hidden iframe.
 * @param {number|string} donorId - The donor ID
 * @param {string} certType - 'progress' for the standard certificate
 *                            (with stats strip, 1200x970) or 'completed'
 *                            for the premium final certificate (1200x850).
 *                            Defaults to 'progress'.
 * @returns {Promise<Blob>} Optimized image blob of the captured certificate
 */
async function captureCertificateFromDonorView(donorId, certType = 'progress') {
    await ensureHtml2CanvasLoaded();

    // Determine which element to capture and its dimensions
    const isCompleted = certType === 'completed';
    const elementId = isCompleted ? 'completed-certificate' : 'donor-certificate';
    const captureHeight = isCompleted ? 850 : 970;

    return new Promise((resolve, reject) => {
        const iframe = document.createElement('iframe');
        iframe.style.position = 'absolute';
        iframe.style.left = '-99999px';
        iframe.style.top = '0';
        iframe.style.width = '1400px';
        iframe.style.height = '2200px';
        iframe.style.border = '0';
        iframe.style.opacity = '0';
        iframe.src = `../donor-management/view-donor.php?id=${encodeURIComponent(donorId)}`;

        let timeoutId = null;
        const cleanup = () => {
            if (timeoutId) clearTimeout(timeoutId);
            if (iframe.parentNode) iframe.parentNode.removeChild(iframe);
        };

        timeoutId = setTimeout(() => {
            cleanup();
            reject(new Error('Timed out loading donor certificate view'));
        }, 25000);

        iframe.onload = async () => {
            try {
                const doc = iframe.contentDocument;
                if (!doc) throw new Error('Unable to load donor certificate document');

                // Expand the certificate accordion so elements are rendered
                const certSection = doc.getElementById('collapseCertificate');
                if (certSection) {
                    certSection.classList.add('show');
                    certSection.style.display = 'block';
                    certSection.style.height = 'auto';
                }

                // For the completed certificate, activate its tab
                if (isCompleted) {
                    const completedTab = doc.getElementById('cert-tab-completed');
                    const progressTab = doc.getElementById('cert-tab-progress');
                    if (completedTab) {
                        completedTab.classList.add('active');
                        completedTab.style.display = 'block';
                    }
                    if (progressTab) {
                        progressTab.classList.remove('active');
                        progressTab.style.display = 'none';
                    }
                }

                const certElement = doc.getElementById(elementId);
                if (!certElement) {
                    const bodyText = (doc.body?.innerText || '').toLowerCase();
                    if (bodyText.includes('access denied') || bodyText.includes('unauthorized')) {
                        throw new Error('Current user cannot access donor certificate view');
                    }
                    throw new Error('Certificate element not found (' + elementId + ')');
                }

                const originalTransform = certElement.style.transform;
                certElement.style.transform = 'none';

                // Allow images and fonts to load
                await new Promise(r => setTimeout(r, 500));

                const canvas = await html2canvas(certElement, {
                    scale: 2,
                    useCORS: true,
                    allowTaint: true,
                    backgroundColor: null,
                    width: 1200,
                    height: captureHeight
                });

                certElement.style.transform = originalTransform;

                const blob = await optimizeCertificateBlob(canvas);

                cleanup();
                resolve(blob);
            } catch (err) {
                cleanup();
                reject(err);
            }
        };

        iframe.onerror = () => {
            cleanup();
            reject(new Error('Failed to load donor view for certificate capture'));
        };

        document.body.appendChild(iframe);
    });
}

// Override sendNotification — sends certificate image with template message
sendNotification = async function() {
    if (!currentNotificationData) return;

    const btn = document.querySelector('.btn-send-notification');
    btn.disabled = true;
    document.getElementById('sendingSpinner').classList.add('active');
    document.getElementById('sendIcon').style.display = 'none';

    try {
        // Get the message that will be sent with the certificate
        const message = isEditMode
            ? document.getElementById('messageTextarea').value
            : currentNotificationData.message;
        const routing = getRoutingTarget(currentNotificationData);

        // Step 1: Load canonical donor certificate data
        document.getElementById('sendBtnText').textContent = 'Generating certificate...';
        const d = await fetchDonorCertificateData(currentNotificationData.donor_id);

        // Step 2: Capture the SAME certificate as donor-management/view-donor.php
        document.getElementById('sendBtnText').textContent = 'Capturing certificate...';
        const blob = await captureCertificateFromDonorView(currentNotificationData.donor_id);

        // Step 2: Send certificate image with template message via WhatsApp
        document.getElementById('sendBtnText').textContent = routing.routedToKesis
            ? 'Sending certificate to ' + routing.assignedName + '...'
            : 'Sending certificate...';

        const formData = new FormData();
        formData.append('certificate', blob, getCertificateFileName('certificate', d.name, blob));
        formData.append('phone', routing.destinationPhone);
        formData.append('donor_id', currentNotificationData.donor_id);
        formData.append('donor_name', d.name);
        formData.append('sqm_value', d.sqm_value);
        formData.append('total_paid', d.currency + parseFloat(d.total_paid).toFixed(2));
        formData.append('message', message); // Send template message with certificate

        const csrfMeta = document.querySelector('meta[name="csrf-token"]');
        const csrfInput = document.querySelector('input[name="csrf_token"]');
        const csrfToken = csrfMeta ? csrfMeta.content : (csrfInput ? csrfInput.value : '');
        formData.append('csrf_token', csrfToken);

        const certRes = await fetch('../donor-management/api/send-certificate-whatsapp.php', {
            method: 'POST',
            body: formData
        });

        const certResult = await certRes.json();

        if (!certResult.success) {
            console.warn('Certificate send failed:', certResult.error);
            throw new Error(certResult.error || 'Failed to send certificate');
        }

        showSendSuccess(
            routing.routedToKesis
                ? 'Sent to ' + routing.assignedName + '!'
                : 'Certificate + Message Sent!'
        );

    } catch (err) {
        console.error('Send error:', err);
        alert('Error: ' + err.message);
        btn.disabled = false;
        document.getElementById('sendingSpinner').classList.remove('active');
        document.getElementById('sendIcon').style.display = '';
        document.getElementById('sendBtnText').textContent = 'Retry';
    }
};

// Helper to show success and close modal
function showSendSuccess(text) {
    document.getElementById('sendingSpinner').classList.remove('active');
    document.getElementById('sendIcon').className = 'fas fa-check me-1';
    document.getElementById('sendIcon').style.display = '';
    document.getElementById('sendBtnText').textContent = text;
    setTimeout(() => {
        if (notificationModal) notificationModal.hide();
    }, 1500);
}

// ===== Fully Paid Modal Logic =====
let fullyPaidModal = null;
let fpEditMode = false;

function showFullyPaidModal(data) {
    currentNotificationData = data;
    fpEditMode = false;

    // Populate donor card
    document.getElementById('fpDonorName').textContent = data.donor_name;
    document.getElementById('fpDonorPhone').textContent = data.donor_phone;
    document.getElementById('fpPledged').textContent = '£' + data.total_pledge;
    document.getElementById('fpPaid').textContent = '£' + data.total_paid;
    document.getElementById('fpThisPayment').textContent = '£' + data.payment_amount;
    document.getElementById('fpArea').textContent = (data.sqm_value || '0') + ' m²';
    renderRoutingNotice(data, 'fpRoutingNotice', 'fpRoutingAgent', 'fpRoutingPhone');

    // Build message from fully_paid_confirmation template
    // Respect template mode: sms => English, auto/whatsapp => Amharic.
    const lang = fullyPaidTemplateMode === 'sms' ? 'en' : 'am';
    let message = '';

    if (dbFullyPaidTemplate) {
        if (lang === 'am' && dbFullyPaidTemplate.message_am) {
            message = dbFullyPaidTemplate.message_am;
        } else if (lang === 'ti' && dbFullyPaidTemplate.message_ti) {
            message = dbFullyPaidTemplate.message_ti;
        } else if (dbFullyPaidTemplate.message_en) {
            message = dbFullyPaidTemplate.message_en;
        }
        // Fallback to Amharic if the selected language is empty
        if (!message && dbFullyPaidTemplate.message_am) {
            message = dbFullyPaidTemplate.message_am;
        }
    }

    // Fallback if no template in database.
    if (!message) {
        if (lang === 'am') {
            message = 'ሰላም ጤና ይስጥልን ወድ {donor_name}፣\n\nሙሉ ቃል ኪዳን ክፍያዎን ስለጨረሱ እናመሰግናለን።\n\nበዛሬው ዕለት ({date}) የተቀበልነው ክፍያ: £{payment_amount}\n\nየቃል ኪዳንዎ ማጠቃለያ፡\n→ ጠቅላላ ቃል ኪዳን: {total_pledged_sqm} ካሬ ሜትር, £{total_pledged}\n→ ጠቅላላ የከፈሉት: £{total_paid}\n→ ቀሪ: £{remaining}\n\nአምላከ ተክለሃይማኖት በሰጡት አብዝቶ ይስጥልን።\n\n- ሊቨርፑል አቡነ ተክለሃይማኖት ቤተ ክርስቲያን';
        } else {
            message = 'Dear {donor_name},\n\nThank you for completing your full pledge payment.\n\nPayment received on {date}: £{payment_amount}\n\nPledge summary:\n→ Total pledge: {total_pledged_sqm} m², £{total_pledged}\n→ Total paid: £{total_paid}\n→ Remaining: £{remaining}\n\n- Liverpool Abune Teklehaymanot Church';
        }
    }

    // Replace variables
    message = message
        .replace(/\{donor_name\}/g, data.donor_name)
        .replace(/\{date\}/g, data.payment_date)
        .replace(/\{payment_amount\}/g, data.payment_amount)
        .replace(/\{total_pledged_sqm\}/g, (data.sqm_value || '0'))
        .replace(/\{total_pledged\}/g, data.total_pledge)
        .replace(/\{total_paid\}/g, data.total_paid)
        .replace(/\{remaining\}/g, '0.00');

    // Handle \n in template (database stores literal \n)
    message = message.replace(/\\n/g, '\n');

    currentNotificationData.fullyPaidMessage = message;
    currentNotificationData.fully_paid_template_mode = fullyPaidTemplateMode;

    // Set message preview
    document.getElementById('fpMessagePreview').textContent = message;
    document.getElementById('fpMessageEdit').value = message;

    // Reset UI state
    document.getElementById('fpMessagePreview').style.display = '';
    document.getElementById('fpMessageEdit').style.display = 'none';
    document.getElementById('fpEditText').textContent = 'Edit message';
    // Reset send button
    const btn = document.getElementById('fpSendBtn');
    btn.disabled = false;
    btn.classList.remove('loading');
    document.getElementById('fpSendText').textContent = 'Send Message';

    // Reset steps
    resetFpSteps();

    // Show modal
    if (!fullyPaidModal) {
        fullyPaidModal = new bootstrap.Modal(document.getElementById('fullyPaidModal'));
    }

    document.getElementById('fullyPaidModal').addEventListener('hidden.bs.modal', function() {
        location.reload();
    }, { once: true });

    fullyPaidModal.show();
}

function toggleFpEdit() {
    fpEditMode = !fpEditMode;
    if (fpEditMode) {
        document.getElementById('fpMessagePreview').style.display = 'none';
        document.getElementById('fpMessageEdit').style.display = '';
        document.getElementById('fpEditText').textContent = 'Preview message';
        document.getElementById('fpMessageEdit').focus();
    } else {
        const edited = document.getElementById('fpMessageEdit').value;
        currentNotificationData.fullyPaidMessage = edited;
        document.getElementById('fpMessagePreview').textContent = edited;
        document.getElementById('fpMessagePreview').style.display = '';
        document.getElementById('fpMessageEdit').style.display = 'none';
        document.getElementById('fpEditText').textContent = 'Edit message';
    }
}

function resetFpSteps() {
    const steps = document.querySelectorAll('#fpSteps .step');
    steps.forEach((s, i) => {
        s.className = 'step' + (i === 0 ? ' active' : '');
    });
}

function setFpStep(stepIndex) {
    const steps = document.querySelectorAll('#fpSteps .step');
    steps.forEach((s, i) => {
        if (i < stepIndex) s.className = 'step done';
        else if (i === stepIndex) s.className = 'step active';
        else s.className = 'step';
    });
}

async function sendFullyPaidNotification() {
    if (!currentNotificationData) return;

    const btn = document.getElementById('fpSendBtn');
    const sendText = document.getElementById('fpSendText');

    // Get message (might be edited) - will be sent with certificate
    const message = fpEditMode
        ? document.getElementById('fpMessageEdit').value
        : currentNotificationData.fullyPaidMessage;
    const routing = getRoutingTarget(currentNotificationData);

    // SMS-only mode: send text notification only (no WhatsApp certificate media flow).
    if ((currentNotificationData.fully_paid_template_mode || fullyPaidTemplateMode) === 'sms') {
        btn.disabled = true;
        btn.classList.add('loading');
        sendText.textContent = 'Sending SMS...';

        try {
            const textRes = await fetch('send-payment-notification.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    donor_id: currentNotificationData.donor_id,
                    phone: routing.destinationPhone,
                    message: message,
                    language: 'en',
                    template_mode: 'sms',
                    template_key: 'fully_paid_confirmation',
                    routed_via_agent: routing.routedToKesis,
                    agent_name: routing.routedToKesis ? routing.assignedName : null
                })
            });
            const textJson = await textRes.json();
            if (!textJson.success) {
                throw new Error(textJson.error || 'Failed to send SMS notification');
            }

            sendText.textContent = 'SMS Sent!';
            btn.querySelector('.btn-icon').className = 'fas fa-check btn-icon';
            btn.querySelector('.btn-icon').style.display = '';
            setTimeout(() => {
                if (fullyPaidModal) fullyPaidModal.hide();
            }, 1200);
        } catch (err) {
            console.error('Fully paid SMS notification error:', err);
            alert('Error: ' + err.message);
            btn.disabled = false;
            btn.classList.remove('loading');
            sendText.textContent = 'Retry';
        }
        return;
    }

    btn.disabled = true;
    btn.classList.add('loading');

    try {
        // Step 1: Generate the FINAL (completed) certificate
        setFpStep(0);
        sendText.textContent = 'Generating final certificate...';

        const d = await fetchDonorCertificateData(currentNotificationData.donor_id);

        sendText.textContent = 'Capturing final certificate...';
        const blob = await captureCertificateFromDonorView(currentNotificationData.donor_id, 'completed');

        setFpStep(1);
        sendText.textContent = routing.routedToKesis
            ? 'Sending certificate to ' + routing.assignedName + '...'
            : 'Sending certificate...';

        const formData = new FormData();
        formData.append('certificate', blob, getCertificateFileName('certificate_final', d.name, blob));
        formData.append('phone', routing.destinationPhone);
        formData.append('donor_id', currentNotificationData.donor_id);
        formData.append('donor_name', d.name);
        formData.append('sqm_value', d.sqm_value);
        formData.append('total_paid', d.currency + parseFloat(d.total_paid).toFixed(2));
        formData.append('message', message); // Send template message with certificate

        const csrfMeta = document.querySelector('meta[name="csrf-token"]');
        const csrfInput = document.querySelector('input[name="csrf_token"]');
        const csrfToken = csrfMeta ? csrfMeta.content : (csrfInput ? csrfInput.value : '');
        formData.append('csrf_token', csrfToken);

        const certRes = await fetch('../donor-management/api/send-certificate-whatsapp.php', {
            method: 'POST',
            body: formData
        });

        const certResult = await certRes.json();

        if (!certResult.success) {
            console.warn('Certificate send failed:', certResult.error);
            throw new Error(certResult.error || 'Failed to send certificate');
        }

        // All done!
        btn.classList.remove('loading');
        const steps = document.querySelectorAll('#fpSteps .step');
        steps.forEach(s => s.className = 'step done');

        sendText.textContent = routing.routedToKesis
            ? 'Sent to ' + routing.assignedName + '!'
            : 'Certificate + Message Sent!';
        btn.querySelector('.btn-icon').className = 'fas fa-check btn-icon';
        btn.querySelector('.btn-icon').style.display = '';

        setTimeout(() => {
            if (fullyPaidModal) fullyPaidModal.hide();
        }, 1500);

    } catch (err) {
        console.error('Fully paid notification error:', err);
        alert('Error: ' + err.message);
        btn.disabled = false;
        btn.classList.remove('loading');
        sendText.textContent = 'Retry';
    }
}

// Text-only fallback (SMS)
async function sendTextFallback() {
    const message = currentNotificationData.message;
    const routing = getRoutingTarget(currentNotificationData);

    const res = await fetch('send-payment-notification.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            donor_id: currentNotificationData.donor_id,
            phone: routing.destinationPhone,
            message: message,
            language: paymentTemplateMode === 'sms' ? 'en' : 'am',
            template_mode: currentNotificationData.template_mode || paymentTemplateMode,
            template_key: 'payment_confirmed',
            routed_via_agent: routing.routedToKesis,
            agent_name: routing.routedToKesis ? routing.assignedName : null
        })
    });

    const result = await res.json();

    if (result.success) {
        document.getElementById('sendingSpinner').classList.remove('active');
        document.getElementById('sendIcon').className = 'fas fa-check me-1';
        document.getElementById('sendIcon').style.display = '';
        document.getElementById('sendBtnText').textContent = 'Sent (SMS)!';

        setTimeout(() => {
            if (notificationModal) notificationModal.hide();
        }, 1200);
    } else {
        throw new Error(result.error || 'SMS fallback failed');
    }
}
</script>
</body>
</html>
