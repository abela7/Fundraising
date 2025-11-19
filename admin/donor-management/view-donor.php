<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
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
        SELECT p.*, u.name as approver_name 
        FROM pledges p 
        LEFT JOIN users u ON p.approved_by_user_id = u.id
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

    // 3. Payments
    $payments = [];
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
        $payments[] = $pay;
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

    // 6. Assignment Info (Church & Representative)
    $assignment = [
        'church_id' => $donor['church_id'] ?? null,
        'church_name' => $donor['church_name'] ?? null,
        'representative_id' => null,
        'representative_name' => null,
        'representative_role' => null,
        'representative_phone' => null
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
        }
        
        .profile-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, #075985 100%);
            color: white;
            padding: 2rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            box-shadow: 0 4px 15px rgba(10, 98, 134, 0.2);
        }
        
        .avatar-circle {
            width: 80px;
            height: 80px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            font-weight: bold;
            margin-right: 1.5rem;
            border: 2px solid rgba(255,255,255,0.3);
        }
        
        .stat-badge {
            background: rgba(255,255,255,0.1);
            padding: 0.5rem 1rem;
            border-radius: 8px;
            text-align: center;
            border: 1px solid rgba(255,255,255,0.2);
        }
        
        .stat-value {
            font-size: 1.25rem;
            font-weight: bold;
            display: block;
        }
        
        .stat-label {
            font-size: 0.75rem;
            opacity: 0.8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
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
            .profile-header {
                padding: 1.5rem;
                text-align: center;
            }
            .profile-header .d-flex.align-items-center {
                flex-direction: column;
                width: 100%;
            }
            .avatar-circle {
                margin-right: 0;
                margin-bottom: 1rem;
            }
            .profile-header .d-flex.gap-3 {
                width: 100%;
                justify-content: center;
                margin-top: 1rem;
            }
            .stat-badge {
                flex: 1 1 100px; /* Flex grow, shrink, basis */
                min-width: 30%;
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
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <a href="donors.php" class="btn btn-outline-secondary btn-lg">
                        <i class="fas fa-arrow-left me-2"></i>Back to Donor List
                    </a>
                    <div class="text-muted small">
                        <i class="fas fa-info-circle me-1"></i>Donor ID: #<?php echo $donor['id']; ?>
                    </div>
                </div>

                <!-- Top Summary Card -->
                <div class="profile-header d-flex flex-wrap align-items-center justify-content-between gap-3">
                    <div class="d-flex align-items-center">
                        <div class="avatar-circle">
                            <?php echo strtoupper(substr($donor['name'], 0, 1)); ?>
                        </div>
                        <div>
                            <h1 class="mb-1 fw-bold"><?php echo htmlspecialchars($donor['name']); ?></h1>
                            <div class="opacity-75 mb-2">
                                <i class="fas fa-map-marker-alt me-1"></i> <?php echo htmlspecialchars($donor['city'] ?? 'Unknown City'); ?> • 
                                <i class="fas fa-phone me-1"></i> <?php echo htmlspecialchars($donor['phone']); ?>
                            </div>
                            <span class="badge bg-light text-dark border-0">
                                ID: #<?php echo $donor['id']; ?>
                            </span>
                            <?php if($donor['baptism_name']): ?>
                            <span class="badge bg-info border-0 ms-2">
                                <i class="fas fa-water me-1"></i> <?php echo htmlspecialchars($donor['baptism_name']); ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="d-flex gap-3 flex-wrap">
                        <div class="stat-badge">
                            <span class="stat-value text-warning"><?php echo formatMoney($donor['total_pledged']); ?></span>
                            <span class="stat-label">Pledged</span>
                        </div>
                        <div class="stat-badge">
                            <span class="stat-value text-success"><?php echo formatMoney($donor['total_paid']); ?></span>
                            <span class="stat-label">Paid</span>
                        </div>
                        <div class="stat-badge">
                            <span class="stat-value text-danger"><?php echo formatMoney($donor['balance']); ?></span>
                            <span class="stat-label">Balance</span>
                        </div>
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
                                                <th>Approved By</th>
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
                                                    <td data-label="Approved By"><?php echo htmlspecialchars($pledge['approver_name'] ?? 'System'); ?></td>
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
                                                <tr><td colspan="8" class="text-center py-3 text-muted">No payments found.</td></tr>
                                            <?php else: ?>
                                                <?php foreach ($payments as $pay): ?>
                                                <tr>
                                                    <td data-label="ID">#<?php echo $pay['id']; ?></td>
                                                    <td data-label="Date"><?php echo formatDate($pay['display_date']); ?></td>
                                                    <td data-label="Amount" class="fw-bold text-success"><?php echo formatMoney($pay['amount']); ?></td>
                                                    <td data-label="Method"><?php echo ucwords(str_replace('_', ' ', $pay['display_method'])); ?></td>
                                                    <td data-label="Reference" class="text-muted small"><?php echo htmlspecialchars($pay['display_ref'] ?? '-'); ?></td>
                                                    <td data-label="Status">
                                                        <span class="badge bg-<?php echo $pay['status'] == 'approved' ? 'success' : 'warning'; ?>">
                                                            <?php echo ucfirst($pay['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td data-label="Approved By"><?php echo htmlspecialchars($pay['approver_name'] ?? 'System'); ?></td>
                                                    <td data-label="Actions">
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
                                <div class="d-flex justify-content-end mb-3">
                                    <button type="button" class="btn btn-sm btn-outline-primary" 
                                            data-bs-toggle="modal" data-bs-target="#editAssignmentModal"
                                            onclick="loadAssignmentData(<?php echo $donor_id; ?>)">
                                        <i class="fas fa-edit me-1"></i>Edit Assignment
                                    </button>
                                    <?php if ($assignment['church_id'] || $assignment['representative_id']): ?>
                                    <a href="delete-assignment.php?donor_id=<?php echo $donor_id; ?>&confirm=no" 
                                       class="btn btn-sm btn-danger ms-2"
                                       onclick="return confirm('Are you sure you want to remove this assignment? This will unassign the donor from the church and representative.');">
                                        <i class="fas fa-trash-alt me-1"></i>Remove Assignment
                                    </a>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
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
                                    <div class="col-md-6">
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
                                </div>
                                
                                <?php if (!$assignment['church_id'] && !$assignment['representative_id']): ?>
                                <div class="alert alert-info mt-3 mb-0">
                                    <i class="fas fa-info-circle me-2"></i>
                                    This donor is not currently assigned to any church or representative.
                                    <a href="../church-management/assign-donors.php?donor_id=<?php echo $donor_id; ?>" class="alert-link">Assign now</a>
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
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="info-row">
                                            <span class="info-label">Registration Source</span>
                                            <span class="info-value"><?php echo ucfirst($donor['source']); ?></span>
                                        </div>
                                        <div class="info-row">
                                            <span class="info-label">Registered By</span>
                                            <span class="info-value"><?php echo htmlspecialchars($donor['registrar_name'] ?? 'System'); ?></span>
                                        </div>
                                        <div class="info-row">
                                            <span class="info-label">Created Date</span>
                                            <span class="info-value"><?php echo formatDateTime($donor['created_at']); ?></span>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="info-row">
                                            <span class="info-label">Last Updated</span>
                                            <span class="info-value"><?php echo formatDateTime($donor['updated_at']); ?></span>
                                        </div>
                                        <div class="info-row">
                                            <span class="info-label">Portal Token</span>
                                            <span class="info-value font-monospace"><?php echo $donor['portal_token'] ? 'Active' : 'None'; ?></span>
                                        </div>
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
                                </div>
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

<!-- Edit Pledge Modal -->
<div class="modal fade" id="editPledgeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-hand-holding-usd me-2"></i>Edit Pledge</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editPledgeForm" method="POST" action="edit-pledge.php">
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
                            <option value="bank_transfer">Bank Transfer</option>
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
                    <div class="mb-3">
                        <label class="form-label">Church <span class="text-danger">*</span></label>
                        <select class="form-select" name="church_id" id="editAssignmentChurchId" required>
                            <option value="">-- Select Church --</option>
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
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Representative <span class="text-danger">*</span></label>
                        <select class="form-select" name="representative_id" id="editAssignmentRepId" required>
                            <option value="">-- Select Church First --</option>
                        </select>
                        <small class="text-muted">Select a church first to load representatives</small>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
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
            loadRepresentatives(churchId);
        });
    }
});

function loadRepresentatives(churchId) {
    const repSelect = document.getElementById('editAssignmentRepId');
    repSelect.innerHTML = '<option value="">-- Loading --</option>';
    
    if (!churchId) {
        repSelect.innerHTML = '<option value="">-- Select Church First --</option>';
        return;
    }
    
    fetch('../church-management/get-representatives.php?church_id=' + churchId)
        .then(response => response.json())
        .then(data => {
            repSelect.innerHTML = '<option value="">-- Select Representative --</option>';
            if (data.representatives && data.representatives.length > 0) {
                data.representatives.forEach(rep => {
                    const option = document.createElement('option');
                    option.value = rep.id;
                    option.textContent = rep.name + (rep.is_primary ? ' (Primary)' : '') + ' - ' + rep.role;
                    repSelect.appendChild(option);
                });
            } else {
                repSelect.innerHTML = '<option value="">No representatives found</option>';
            }
        })
        .catch(error => {
            console.error('Error loading representatives:', error);
            repSelect.innerHTML = '<option value="">Error loading representatives</option>';
        });
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
</body>
</html>
