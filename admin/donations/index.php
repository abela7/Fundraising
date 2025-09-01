<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

// Resiliently load settings and check for DB errors
require_once __DIR__ . '/../includes/resilient_db_loader.php';

$page_title = 'Donations Management';
$current_user = current_user();
$db = db();

// Handle POST actions (Update, Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $actionMsg = '';
    
    try {
        $db->begin_transaction();
        $uid = (int)$current_user['id'];
        
        if ($action === 'create_donation') {
            $donationType = $_POST['donation_type'] === 'payment' ? 'payment' : 'pledge';
            $donorName = trim($_POST['donor_name'] ?? '');
            $donorPhone = trim($_POST['donor_phone'] ?? '');
            $donorEmail = trim($_POST['donor_email'] ?? '');
            $amount = (float)($_POST['amount'] ?? 0);
            $packageId = isset($_POST['package_id']) && $_POST['package_id'] !== '' ? (int)$_POST['package_id'] : null;
            $selectedRegistrar = isset($_POST['registrar_id']) && $_POST['registrar_id'] !== '' ? (int)$_POST['registrar_id'] : 0;

            if ($amount <= 0 && $packageId === null) {
                throw new Exception('Amount must be greater than zero or choose a package.');
            }

            // Optional package enforcement of amount
            if ($packageId !== null) {
                $pkgStmt = $db->prepare('SELECT price FROM donation_packages WHERE id = ?');
                $pkgStmt->bind_param('i', $packageId);
                $pkgStmt->execute();
                $pkg = $pkgStmt->get_result()->fetch_assoc();
                $pkgStmt->close();
                if (!$pkg) {
                    throw new Exception('Selected package was not found.');
                }
                $amount = (float)$pkg['price'];
            }

            if ($donationType === 'payment') {
                $method = $_POST['method'] ?? 'cash';
                $status = $_POST['status'] ?? 'pending';
                $reference = trim($_POST['reference'] ?? '');
                $receivedBy = $selectedRegistrar > 0 ? $selectedRegistrar : $uid;

                if ($packageId === null) {
                    $sql = 'INSERT INTO payments (donor_name, donor_phone, donor_email, amount, method, package_id, reference, status, received_by_user_id, received_at) VALUES (?,?,?,?,?, NULL, ?, ?, ?, NOW())';
                    $ins = $db->prepare($sql);
                    $ins->bind_param('sssdsissi', $donorName, $donorPhone, $donorEmail, $amount, $method, $reference, $status, $receivedBy);
                } else {
                    $sql = 'INSERT INTO payments (donor_name, donor_phone, donor_email, amount, method, package_id, reference, status, received_by_user_id, received_at) VALUES (?,?,?,?,?,?,?,?,?, NOW())';
                    $ins = $db->prepare($sql);
                    $ins->bind_param('sssdsissii', $donorName, $donorPhone, $donorEmail, $amount, $method, $packageId, $reference, $status, $receivedBy);
                }
                $ins->execute();
                $newId = (int)$db->insert_id;

                // Audit log
                $after = json_encode(['donor_name'=>$donorName,'amount'=>$amount,'method'=>$method,'status'=>$status,'received_by_user_id'=>$receivedBy], JSON_UNESCAPED_SLASHES);
                $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'payment', ?, 'create', NULL, ?, 'admin')");
                $log->bind_param('iis', $uid, $newId, $after);
                $log->execute();

                $actionMsg = 'Payment created successfully (#'.$newId.')';

            } else { // pledge
                $status = $_POST['status'] ?? 'pending';
                $pledgeType = $_POST['pledge_type'] === 'paid' ? 'paid' : 'pledge';
                $anonymous = isset($_POST['anonymous']) ? 1 : 0;
                $notes = trim($_POST['notes'] ?? '');
                $createdBy = $selectedRegistrar > 0 ? $selectedRegistrar : $uid;
                $source = 'volunteer';

                if ($packageId === null) {
                    // Exclude package_id placeholder and use NULL directly to avoid FK constraint issues
                    $sql = 'INSERT INTO pledges (donor_name, donor_phone, donor_email, package_id, source, anonymous, amount, type, status, notes, created_by_user_id, approved_by_user_id, created_at, approved_at, status_changed_at) VALUES (?,?,?, NULL, ?, ?, ?, ?, ?, ?, ?, NULL, NOW(), NULL, NOW())';
                    $ins = $db->prepare($sql);
                    // types: s s s s i d s s s i => ssssidsssi
                    $ins->bind_param('ssssidsssi', $donorName, $donorPhone, $donorEmail, $source, $anonymous, $amount, $pledgeType, $status, $notes, $createdBy);
                } else {
                    $sql = 'INSERT INTO pledges (donor_name, donor_phone, donor_email, package_id, source, anonymous, amount, type, status, notes, created_by_user_id, approved_by_user_id, created_at, approved_at, status_changed_at) VALUES (?,?,?,?,?, ?, ?, ?, ?, ?, ?, NULL, NOW(), NULL, NOW())';
                    $ins = $db->prepare($sql);
                    // types: s s s i s i d s s s i => sssisidsssi
                    $ins->bind_param('sssisidsssi', $donorName, $donorPhone, $donorEmail, $packageId, $source, $anonymous, $amount, $pledgeType, $status, $notes, $createdBy);
                }
                $ins->execute();
                $newId = (int)$db->insert_id;

                // Audit log
                $after = json_encode(['donor_name'=>$donorName,'amount'=>$amount,'status'=>$status,'created_by_user_id'=>$createdBy,'type'=>$pledgeType], JSON_UNESCAPED_SLASHES);
                $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'pledge', ?, 'create', NULL, ?, 'admin')");
                $log->bind_param('iis', $uid, $newId, $after);
                $log->execute();

                $actionMsg = 'Pledge created successfully (#'.$newId.')';
            }

        } elseif ($action === 'update_payment') {
            $paymentId = (int)($_POST['payment_id'] ?? 0);
            $donorName = trim($_POST['donor_name'] ?? '');
            $donorPhone = trim($_POST['donor_phone'] ?? '');
            $donorEmail = trim($_POST['donor_email'] ?? '');
            $amount = (float)($_POST['amount'] ?? 0);
            $method = $_POST['method'] ?? 'cash';
            $reference = trim($_POST['reference'] ?? '');
            $status = $_POST['status'] ?? 'pending';
            
            if ($paymentId <= 0 || $amount <= 0) {
                throw new Exception('Invalid payment data');
            }
            
            // Get original record for audit
            $orig = $db->prepare("SELECT * FROM payments WHERE id = ?");
            $orig->bind_param('i', $paymentId);
            $orig->execute();
            $originalData = $orig->get_result()->fetch_assoc();
            if (!$originalData) {
                throw new Exception('Payment not found');
            }
            
            $sql = "UPDATE payments SET donor_name=?, donor_phone=?, donor_email=?, amount=?, method=?, reference=?, status=? WHERE id=?";
            $stmt = $db->prepare($sql);
            $stmt->bind_param('sssdsssi', $donorName, $donorPhone, $donorEmail, $amount, $method, $reference, $status, $paymentId);
            $stmt->execute();
            
            // Audit log
            $before = json_encode($originalData, JSON_UNESCAPED_SLASHES);
            $after = json_encode(['donor_name'=>$donorName,'amount'=>$amount,'method'=>$method,'status'=>$status], JSON_UNESCAPED_SLASHES);
            $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'payment', ?, 'update', ?, ?, 'admin')");
            $log->bind_param('iiss', $uid, $paymentId, $before, $after);
            $log->execute();
            
            $actionMsg = 'Payment updated successfully';
            
        } elseif ($action === 'update_pledge') {
            $pledgeId = (int)($_POST['pledge_id'] ?? 0);
            $donorName = trim($_POST['donor_name'] ?? '');
            $donorPhone = trim($_POST['donor_phone'] ?? '');
            $donorEmail = trim($_POST['donor_email'] ?? '');
            $amount = (float)($_POST['amount'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');
            $status = $_POST['status'] ?? 'pending';
            $anonymous = isset($_POST['anonymous']) ? 1 : 0;
            
            if ($pledgeId <= 0 || $amount <= 0) {
                throw new Exception('Invalid pledge data');
            }
            
            // Get original record for audit
            $orig = $db->prepare("SELECT * FROM pledges WHERE id = ?");
            $orig->bind_param('i', $pledgeId);
            $orig->execute();
            $originalData = $orig->get_result()->fetch_assoc();
            if (!$originalData) {
                throw new Exception('Pledge not found');
            }
            
            $sql = "UPDATE pledges SET donor_name=?, donor_phone=?, donor_email=?, amount=?, notes=?, status=?, anonymous=? WHERE id=?";
            $stmt = $db->prepare($sql);
            $stmt->bind_param('sssdssii', $donorName, $donorPhone, $donorEmail, $amount, $notes, $status, $anonymous, $pledgeId);
            $stmt->execute();
            
            // Audit log
            $before = json_encode($originalData, JSON_UNESCAPED_SLASHES);
            $after = json_encode(['donor_name'=>$donorName,'amount'=>$amount,'status'=>$status,'anonymous'=>$anonymous], JSON_UNESCAPED_SLASHES);
            $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'pledge', ?, 'update', ?, ?, 'admin')");
            $log->bind_param('iiss', $uid, $pledgeId, $before, $after);
            $log->execute();
            
            $actionMsg = 'Pledge updated successfully';
            
        } elseif ($action === 'delete_payment') {
            $paymentId = (int)($_POST['payment_id'] ?? 0);
            if ($paymentId <= 0) {
                throw new Exception('Invalid payment ID');
            }
            
            // Get original record for audit
            $orig = $db->prepare("SELECT * FROM payments WHERE id = ?");
            $orig->bind_param('i', $paymentId);
            $orig->execute();
            $originalData = $orig->get_result()->fetch_assoc();
            if (!$originalData) {
                throw new Exception('Payment not found');
            }
            
            $stmt = $db->prepare("DELETE FROM payments WHERE id = ?");
            $stmt->bind_param('i', $paymentId);
            $stmt->execute();
            
            // Audit log
            $before = json_encode($originalData, JSON_UNESCAPED_SLASHES);
            $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'payment', ?, 'delete', ?, NULL, 'admin')");
            $log->bind_param('iis', $uid, $paymentId, $before);
            $log->execute();
            
            $actionMsg = 'Payment deleted successfully';
            
        } elseif ($action === 'delete_pledge') {
            $pledgeId = (int)($_POST['pledge_id'] ?? 0);
            if ($pledgeId <= 0) {
                throw new Exception('Invalid pledge ID');
            }
            
            // Get original record for audit
            $orig = $db->prepare("SELECT * FROM pledges WHERE id = ?");
            $orig->bind_param('i', $pledgeId);
            $orig->execute();
            $originalData = $orig->get_result()->fetch_assoc();
            if (!$originalData) {
                throw new Exception('Pledge not found');
            }
            
            $stmt = $db->prepare("DELETE FROM pledges WHERE id = ?");
            $stmt->bind_param('i', $pledgeId);
            $stmt->execute();
            
            // Audit log
            $before = json_encode($originalData, JSON_UNESCAPED_SLASHES);
            $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'pledge', ?, 'delete', ?, NULL, 'admin')");
            $log->bind_param('iis', $uid, $pledgeId, $before);
            $log->execute();
            
            $actionMsg = 'Pledge deleted successfully';
        }
        
        $db->commit();
    } catch (Exception $e) {
        $db->rollback();
        $actionMsg = 'Error: ' . $e->getMessage();
    }
    
    header('Location: index.php?msg=' . urlencode($actionMsg));
    exit;
}

// Get filter parameters
$allowedStatuses = ['all','pending','approved','rejected','voided', 'cancelled'];
$allowedTypes = ['all','payment','pledge'];
$allowedMethods = ['all','cash','bank','card','other'];

$statusFilter = in_array($_GET['status'] ?? 'all', $allowedStatuses, true) ? ($_GET['status'] ?? 'all') : 'all';
$typeFilter = in_array($_GET['type'] ?? 'all', $allowedTypes, true) ? ($_GET['type'] ?? 'all') : 'all';
$methodFilter = in_array($_GET['method'] ?? 'all', $allowedMethods, true) ? ($_GET['method'] ?? 'all') : 'all';
$search = trim($_GET['search'] ?? '');
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

// Corrected Base UNION Query
$baseQuery = "
(SELECT 
    p.id,
    'payment' as donation_type,
    p.donor_name,
    p.donor_phone,
    p.donor_email,
    p.amount,
    p.method,
    p.reference as notes,
    p.status,
    0 as anonymous,
    NULL as pledge_type,
    p.created_at,
    p.received_at as processed_at,
    u.name as processed_by,
    dp.label as package_label,
    dp.sqm_meters as package_sqm
 FROM payments p
 LEFT JOIN users u ON p.received_by_user_id = u.id
 LEFT JOIN donation_packages dp ON p.package_id = dp.id)
UNION ALL
(SELECT 
    pl.id,
    'pledge' as donation_type,
    pl.donor_name,
    pl.donor_phone,
    pl.donor_email,
    pl.amount,
    NULL as method,
    pl.notes,
    pl.status,
    pl.anonymous,
    pl.type as pledge_type,
    pl.created_at,
    pl.approved_at as processed_at,
    u2.name as processed_by,
    dp2.label as package_label,
    dp2.sqm_meters as package_sqm
 FROM pledges pl
 LEFT JOIN users u2 ON pl.approved_by_user_id = u2.id
 LEFT JOIN donation_packages dp2 ON pl.package_id = dp2.id)";

// Build WHERE clause
$where_conditions = [];
$params = [];
$types = '';

if ($typeFilter !== 'all') {
    $where_conditions[] = "donation_type = ?";
    $params[] = $typeFilter;
    $types .= 's';
}

if ($statusFilter !== 'all') {
    $where_conditions[] = "status = ?";
    $params[] = $statusFilter;
    $types .= 's';
}

if ($methodFilter !== 'all' && $typeFilter !== 'pledge') {
    $where_conditions[] = "method = ?";
    $params[] = $methodFilter;
    $types .= 's';
}

if ($search !== '') {
    $where_conditions[] = "(donor_name LIKE ? OR donor_phone LIKE ? OR donor_email LIKE ? OR notes LIKE ?)";
    $searchTerm = '%' . $search . '%';
    array_push($params, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
    $types .= 'ssss';
}

if ($dateFrom !== '') {
    $where_conditions[] = "DATE(created_at) >= ?";
    $params[] = $dateFrom;
    $types .= 's';
}

if ($dateTo !== '') {
    $where_conditions[] = "DATE(created_at) <= ?";
    $params[] = $dateTo;
    $types .= 's';
}

$whereClause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

// Get total count with error handling
$totalRecords = 0;
$countSql = "SELECT COUNT(*) as total FROM ($baseQuery) as combined_donations $whereClause";
$countStmt = $db->prepare($countSql);
if ($countStmt) {
    if (!empty($params)) {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $result = $countStmt->get_result();
    if($result) {
        $totalRecords = (int)($result->fetch_assoc()['total'] ?? 0);
    }
    $countStmt->close();
}
$totalPages = (int)ceil($totalRecords / $perPage);

// Get paginated results with error handling
$donations = [];
$sql = "SELECT * FROM ($baseQuery) as combined_donations $whereClause ORDER BY created_at DESC LIMIT ? OFFSET ?";
$stmt = $db->prepare($sql);
if ($stmt) {
    $allParams = $params;
    array_push($allParams, $perPage, $offset);
    $allTypes = $types . 'ii';
    
    $stmt->bind_param($allTypes, ...$allParams);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        $donations = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}

// Get statistics
$statsResult = $db->query("
    SELECT 
        (SELECT COUNT(*) FROM payments) + (SELECT COUNT(*) FROM pledges) as total_count,
        (SELECT COUNT(*) FROM payments) as payment_count,
        (SELECT COUNT(*) FROM pledges) as pledge_count,
        (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status='approved') + (SELECT COALESCE(SUM(amount), 0) FROM pledges WHERE status='approved') as approved_amount,
        (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status='pending') + (SELECT COALESCE(SUM(amount), 0) FROM pledges WHERE status='pending') as pending_amount,
        (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status='approved') as paid_amount,
        (SELECT COALESCE(SUM(amount), 0) FROM pledges WHERE status='approved') as pledged_amount
")->fetch_assoc();

$stats = [
    'total_count' => (int)($statsResult['total_count'] ?? 0),
    'payment_count' => (int)($statsResult['payment_count'] ?? 0),
    'pledge_count' => (int)($statsResult['pledge_count'] ?? 0),
    'approved_amount' => (float)($statsResult['approved_amount'] ?? 0),
    'pending_amount' => (float)($statsResult['pending_amount'] ?? 0),
    'paid_amount' => (float)($statsResult['paid_amount'] ?? 0),
    'pledged_amount' => (float)($statsResult['pledged_amount'] ?? 0)
];

// Get currency from settings
$settings = $db->query('SELECT currency_code FROM settings WHERE id=1')->fetch_assoc();
$currency = $settings['currency_code'] ?? 'GBP';

// Dropdown data for Add Donation
$registrars = $db->query("SELECT id, name FROM users WHERE (role='registrar' OR role='admin') AND active=1 ORDER BY name")?->fetch_all(MYSQLI_ASSOC) ?? [];
$packages = $db->query("SELECT id, label, price FROM donation_packages WHERE active=1 ORDER BY price")?->fetch_all(MYSQLI_ASSOC) ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Donations Management - Fundraising Admin</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <link rel="stylesheet" href="assets/donations.css">
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include __DIR__ . '/../includes/topbar.php'; ?>
        <main class="main-content">
            <div class="container-fluid">
                <?php include __DIR__ . '/../includes/db_error_banner.php'; ?>
        
            <!-- Success/Error Messages -->
            <?php if (isset($_GET['msg'])): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <i class="fas fa-info-circle me-2"></i>
                <?php echo htmlspecialchars($_GET['msg']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Page Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-1 text-primary">
                        <i class="fas fa-donate me-2"></i>Donations Management
                    </h1>
                    <p class="text-muted mb-0">Comprehensive view and management of all payments and pledges</p>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-primary" onclick="exportDonations()">
                        <i class="fas fa-download me-2"></i>Export
                    </button>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addDonationModal">
                        <i class="fas fa-plus me-2"></i>Add Donation
                    </button>
                </div>
            </div>

            <!-- Statistics Dashboard -->
            <div class="row g-3 mb-4">
                <div class="col-12 col-sm-6 col-lg-3">
                    <div class="stat-card stat-card-primary">
                        <div class="stat-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo number_format($stats['total_count']); ?></div>
                            <div class="stat-label">Total Donations</div>
                            <div class="stat-detail">
                                <?php echo number_format($stats['payment_count']); ?> payments, 
                                <?php echo number_format($stats['pledge_count']); ?> pledges
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-sm-6 col-lg-3">
                    <div class="stat-card stat-card-success">
                        <div class="stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo $currency; ?> <?php echo number_format($stats['approved_amount'], 0); ?></div>
                            <div class="stat-label">Approved Total</div>
                            <div class="stat-detail">
                                Paid: <?php echo $currency; ?><?php echo number_format($stats['paid_amount'], 0); ?> | 
                                Pledged: <?php echo $currency; ?><?php echo number_format($stats['pledged_amount'], 0); ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-sm-6 col-lg-3">
                    <div class="stat-card stat-card-warning">
                        <div class="stat-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo $currency; ?> <?php echo number_format($stats['pending_amount'], 0); ?></div>
                            <div class="stat-label">Pending Review</div>
                            <div class="stat-detail">Awaiting approval</div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-sm-6 col-lg-3">
                    <div class="stat-card stat-card-info">
                        <div class="stat-icon">
                            <i class="fas fa-percentage"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?php 
                                $total_raised = $stats['approved_amount'] + $stats['pending_amount'];
                                $approvalRate = $total_raised > 0 ? round(($stats['approved_amount'] / $total_raised) * 100, 1) : 0;
                                echo $approvalRate; ?>%</div>
                            <div class="stat-label">Approval Rate</div>
                            <div class="stat-detail">Of total submitted</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Advanced Filters -->
            <div class="card mb-4">
                <div class="card-header bg-light">
                    <h6 class="mb-0">
                        <i class="fas fa-filter me-2"></i>Advanced Filters
                        <button class="btn btn-sm btn-outline-secondary float-end" type="button" data-bs-toggle="collapse" data-bs-target="#filtersCollapse">
                            <i class="fas fa-chevron-down"></i>
                        </button>
                    </h6>
                </div>
                <div class="collapse show" id="filtersCollapse">
                    <div class="card-body">
                        <form method="get" class="row g-3" id="filterForm">
                            <div class="col-lg-2 col-md-4 col-sm-6">
                                <label class="form-label">Donation Type</label>
                                <select name="type" class="form-select" onchange="submitFilters()">
                                    <option value="all" <?php echo $typeFilter === 'all' ? 'selected' : ''; ?>>All Types</option>
                                    <option value="payment" <?php echo $typeFilter === 'payment' ? 'selected' : ''; ?>>Payments</option>
                                    <option value="pledge" <?php echo $typeFilter === 'pledge' ? 'selected' : ''; ?>>Pledges</option>
                                </select>
                            </div>
                            <div class="col-lg-2 col-md-4 col-sm-6">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select" onchange="submitFilters()">
                                    <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                    <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="approved" <?php echo $statusFilter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                    <option value="rejected" <?php echo $statusFilter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                    <option value="voided" <?php echo $statusFilter === 'voided' ? 'selected' : ''; ?>>Voided</option>
                                    <option value="cancelled" <?php echo $statusFilter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                            </div>
                            <div class="col-lg-2 col-md-4 col-sm-6">
                                <label class="form-label">Payment Method</label>
                                <select name="method" class="form-select" onchange="submitFilters()" <?php if ($typeFilter === 'pledge') echo 'disabled'; ?>>
                                    <option value="all" <?php echo $methodFilter === 'all' ? 'selected' : ''; ?>>All Methods</option>
                                    <option value="cash" <?php echo $methodFilter === 'cash' ? 'selected' : ''; ?>>Cash</option>
                                    <option value="bank" <?php echo $methodFilter === 'bank' ? 'selected' : ''; ?>>Bank Transfer</option>
                                    <option value="card" <?php echo $methodFilter === 'card' ? 'selected' : ''; ?>>Card</option>
                                    <option value="other" <?php echo $methodFilter === 'other' ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                            <div class="col-lg-2 col-md-4 col-sm-6">
                                <label class="form-label">From Date</label>
                                <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($dateFrom); ?>" onchange="submitFilters()">
                            </div>
                            <div class="col-lg-2 col-md-4 col-sm-6">
                                <label class="form-label">To Date</label>
                                <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($dateTo); ?>" onchange="submitFilters()">
                            </div>
                            <div class="col-lg-2 col-md-4 col-sm-6">
                                <label class="form-label">Search</label>
                                <div class="input-group">
                                    <input type="text" name="search" class="form-control" placeholder="Name, phone, email..." value="<?php echo htmlspecialchars($search); ?>">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-search"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-filter me-2"></i>Apply Filters
                                    </button>
                                    <a href="?" class="btn btn-outline-secondary">
                                        <i class="fas fa-undo me-2"></i>Clear All
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Donations Table -->
            <div class="card">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">
                        <i class="fas fa-table me-2"></i>Donations List
                    </h6>
                    <div class="text-muted small">
                        Showing <?php echo number_format(count($donations)); ?> of <?php echo number_format($totalRecords); ?> donations
                    </div>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($donations)): ?>
                    <div class="empty-state text-center py-5">
                        <i class="fas fa-search text-muted mb-3" style="font-size: 3rem;"></i>
                        <h5 class="text-muted">No donations found</h5>
                        <p class="text-muted">Try adjusting your filters or search criteria.</p>
                        <a href="?" class="btn btn-outline-primary">Clear Filters</a>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="border-0">ID</th>
                                    <th class="border-0">Date & Time</th>
                                    <th class="border-0">Type</th>
                                    <th class="border-0">Donor</th>
                                    <th class="border-0">Amount</th>
                                    <th class="border-0">Method</th>
                                    <th class="border-0">Status</th>
                                    <th class="border-0">Package</th>
                                    <th class="border-0">Processed By</th>
                                    <th class="border-0 text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($donations as $donation): ?>
                                <tr class="donation-row">
                                    <td>
                                        <span class="text-primary fw-bold">
                                            <?php echo strtoupper(substr($donation['donation_type'], 0, 1)) . str_pad((string)$donation['id'], 4, '0', STR_PAD_LEFT); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="timestamp">
                                            <div class="fw-medium"><?php echo date('d M Y', strtotime($donation['created_at'])); ?></div>
                                            <small class="text-muted"><?php echo date('h:i A', strtotime($donation['created_at'])); ?></small>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($donation['donation_type'] === 'payment'): ?>
                                        <span class="badge bg-success">
                                            <i class="fas fa-credit-card me-1"></i>Payment
                                        </span>
                                        <?php else: ?>
                                        <span class="badge bg-warning">
                                            <i class="fas fa-handshake me-1"></i>Pledge
                                        </span>
                                        <?php if ($donation['pledge_type'] === 'paid'): ?>
                                        <span class="badge bg-info mt-1">
                                            <i class="fas fa-check me-1"></i>Paid at Reg.
                                        </span>
                                        <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="donor-info">
                                            <?php if ($donation['anonymous']): ?>
                                            <div class="fw-medium text-muted">
                                                <i class="fas fa-user-secret me-1"></i>Anonymous
                                            </div>
                                            <?php else: ?>
                                            <div class="fw-medium"><?php echo htmlspecialchars($donation['donor_name'] ?: 'N/A'); ?></div>
                                            <?php if ($donation['donor_phone']): ?>
                                            <small class="text-muted"><?php echo htmlspecialchars($donation['donor_phone']); ?></small>
                                            <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="fw-bold text-success"><?php echo $currency; ?> <?php echo number_format((float)$donation['amount'], 2); ?></span>
                                    </td>
                                    <td>
                                        <?php if ($donation['method']): ?>
                                        <?php 
                                        $methodIcons = [
                                            'cash' => 'fas fa-money-bill-wave',
                                            'bank' => 'fas fa-university',
                                            'card' => 'fas fa-credit-card',
                                            'other' => 'fas fa-question-circle'
                                        ];
                                        $icon = $methodIcons[$donation['method']] ?? 'fas fa-question-circle';
                                        ?>
                                        <span class="badge bg-secondary">
                                            <i class="<?php echo $icon; ?> me-1"></i><?php echo ucfirst($donation['method']); ?>
                                        </span>
                                        <?php else: ?>
                                        <span class="text-muted">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $statusColors = [
                                            'pending' => 'warning',
                                            'approved' => 'success',
                                            'rejected' => 'danger',
                                            'voided' => 'secondary',
                                            'cancelled' => 'dark'
                                        ];
                                        $statusIcons = [
                                            'pending' => 'fas fa-clock',
                                            'approved' => 'fas fa-check-circle',
                                            'rejected' => 'fas fa-times-circle',
                                            'voided' => 'fas fa-ban',
                                            'cancelled' => 'fas fa-minus-circle'
                                        ];
                                        $status = $donation['status'];
                                        $color = $statusColors[$status] ?? 'secondary';
                                        $icon = $statusIcons[$status] ?? 'fas fa-question-circle';
                                        ?>
                                        <span class="badge bg-<?php echo $color; ?>">
                                            <i class="<?php echo $icon; ?> me-1"></i><?php echo ucfirst($status); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($donation['package_label']): ?>
                                        <span class="badge bg-light text-dark border">
                                            <?php echo htmlspecialchars($donation['package_label']); ?>
                                            <?php if ($donation['package_sqm']): ?>
                                            <br><small>(<?php echo number_format((float)$donation['package_sqm'], 2); ?> m²)</small>
                                            <?php endif; ?>
                                        </span>
                                        <?php else: ?>
                                        <span class="text-muted">Custom</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?php echo htmlspecialchars($donation['processed_by'] ?: 'System'); ?></small>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group" role="group">
                                            <button class="btn btn-sm btn-outline-primary" 
                                                    onclick="viewDonationDetails(<?php echo htmlspecialchars(json_encode($donation)); ?>)" 
                                                    title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline-secondary" 
                                                    onclick="editDonation(<?php echo htmlspecialchars(json_encode($donation)); ?>)" 
                                                    title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline-danger" 
                                                    onclick="deleteDonation('<?php echo $donation['donation_type']; ?>', <?php echo $donation['id']; ?>, '<?php echo htmlspecialchars($donation['donor_name'] ?: 'Anonymous'); ?>')" 
                                                    title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                    <nav class="mt-4">
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-2">
                            <div class="text-muted small">
                                Showing <?php echo number_format(($page - 1) * $perPage + 1); ?>–<?php echo number_format(min($page * $perPage, $totalRecords)); ?> of <?php echo number_format($totalRecords); ?>
                            </div>
                            <ul class="pagination mb-0">
                                <?php
                                $queryBase = $_GET;
                                unset($queryBase['page']);
                                $queryString = http_build_query($queryBase);
                                ?>
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?<?php echo $queryString ? $queryString . '&' : ''; ?>page=<?php echo max(1, $page - 1); ?>">
                                        <i class="fas fa-chevron-left"></i>
                                    </a>
                                </li>
                                <?php 
                                    $start = max(1, $page - 2);
                                    $end = min($totalPages, $page + 2);

                                    if ($start > 1) {
                                        echo '<li class="page-item"><a class="page-link" href="?'.($queryString ? $queryString.'&' : '').'page=1">1</a></li>';
                                        if ($start > 2) {
                                            echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                                        }
                                    }

                                    for ($i = $start; $i <= $end; $i++): ?>
                                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?<?php echo $queryString ? $queryString . '&' : ''; ?>page=<?php echo $i; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                                <?php endfor; 
                                
                                    if ($end < $totalPages) {
                                        if ($end < $totalPages - 1) {
                                            echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                                        }
                                        echo '<li class="page-item"><a class="page-link" href="?'.($queryString ? $queryString.'&' : '').'page='.$totalPages.'">'.$totalPages.'</a></li>';
                                    }
                                ?>
                                <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?<?php echo $queryString ? $queryString . '&' : ''; ?>page=<?php echo min($totalPages, $page + 1); ?>">
                                        <i class="fas fa-chevron-right"></i>
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </nav>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Add Donation Modal -->
<div class="modal fade" id="addDonationModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus text-primary me-2"></i>Add Donation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" id="addDonationForm">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="create_donation">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Donation Type</label>
                            <select class="form-select" name="donation_type" id="donationTypeSelect">
                                <option value="payment">Payment</option>
                                <option value="pledge">Pledge</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Registrar</label>
                            <select class="form-select" name="registrar_id">
                                <option value="">Current User</option>
                                <?php foreach ($registrars as $r): ?>
                                <option value="<?php echo (int)$r['id']; ?>"><?php echo htmlspecialchars($r['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Package (optional)</label>
                            <select class="form-select" name="package_id" id="addPackageSelect" onchange="(function(){var s=document.getElementById('addPackageSelect');var a=document.getElementById('addAmount');var p=s.options[s.selectedIndex]?.getAttribute('data-price'); if(p){a.value=parseFloat(p).toFixed(2);}})()">
                                <option value="">Custom Amount</option>
                                <?php foreach ($packages as $p): ?>
                                <option value="<?php echo (int)$p['id']; ?>" data-price="<?php echo number_format((float)$p['price'],2,'.',''); ?>"><?php echo htmlspecialchars($p['label']); ?> - £<?php echo number_format((float)$p['price'],2); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Donor Name</label>
                            <input type="text" class="form-control" name="donor_name">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Phone</label>
                            <input type="text" class="form-control" name="donor_phone">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="donor_email">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Amount</label>
                            <div class="input-group">
                                <span class="input-group-text">£</span>
                                <input type="number" class="form-control" id="addAmount" name="amount" step="0.01" min="0.01">
                            </div>
                        </div>
                        <div class="col-md-4 payment-only">
                            <label class="form-label">Method</label>
                            <select class="form-select" name="method">
                                <option value="cash">Cash</option>
                                <option value="bank">Bank Transfer</option>
                                <option value="card">Card</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-4 payment-only">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <option value="pending">Pending</option>
                                <option value="approved">Approved</option>
                                <option value="voided">Voided</option>
                            </select>
                        </div>
                        <div class="col-12 payment-only">
                            <label class="form-label">Reference</label>
                            <input type="text" class="form-control" name="reference" placeholder="Receipt #, Transaction ID, etc.">
                        </div>

                        <div class="col-md-4 pledge-only d-none">
                            <label class="form-label">Pledge Type</label>
                            <select class="form-select" name="pledge_type">
                                <option value="pledge">Pledge</option>
                                <option value="paid">Paid (at registration)</option>
                            </select>
                        </div>
                        <div class="col-md-4 pledge-only d-none">
                            <div class="form-check mt-4">
                                <input class="form-check-input" type="checkbox" name="anonymous" id="addAnon">
                                <label class="form-check-label" for="addAnon">Anonymous</label>
                            </div>
                        </div>
                        <div class="col-12 pledge-only d-none">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Create</button>
                </div>
            </form>
        </div>
    </div>
    <script>
    (function(){
        const typeSel = document.getElementById('donationTypeSelect');
        function toggleSections(){
            const isPayment = typeSel.value === 'payment';
            document.querySelectorAll('.payment-only').forEach(el=>el.classList.toggle('d-none', !isPayment));
            document.querySelectorAll('.pledge-only').forEach(el=>el.classList.toggle('d-none', isPayment));
        }
        if (typeSel) {
            typeSel.addEventListener('change', toggleSections);
            toggleSections();
        }
    })();
    </script>
</div>

<!-- View Details Modal -->
<div class="modal fade" id="detailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-info-circle text-primary me-2"></i>Donation Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detailsModalBody">
                <!-- Details will be populated by JavaScript -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-edit text-warning me-2"></i>Edit Donation
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" id="editForm">
                <?php echo csrf_input(); ?>
                <div class="modal-body" id="editModalBody">
                    <!-- Form will be populated by JavaScript -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-exclamation-triangle text-danger me-2"></i>Confirm Deletion
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this donation?</p>
                <p class="text-danger"><strong>This action cannot be undone.</strong></p>
                <div id="deleteDetails"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="post" style="display: inline;" id="deleteForm">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" id="deleteAction">
                    <input type="hidden" name="payment_id" id="deletePaymentId">
                    <input type="hidden" name="pledge_id" id="deletePledgeId">
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash me-2"></i>Delete
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script src="assets/donations.js"></script>
<script>
// Fallback for sidebar toggle if admin.js failed to attach for any reason
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
