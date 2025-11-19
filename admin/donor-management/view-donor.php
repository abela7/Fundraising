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
    return $date ? date('M j, Y g:i A', strtotime($date)) : '-';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($donor['name']); ?> - Donor Profile</title>
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
                    <a href="donors.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to List
                    </a>
                    <div class="d-flex gap-2">
                        <a href="../../call-center/make-call.php?donor_id=<?php echo $donor['id']; ?>" class="btn btn-success">
                            <i class="fas fa-phone-alt me-2"></i>Call Donor
                        </a>
                        <button class="btn btn-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <i class="fas fa-cog me-2"></i>Actions
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-menu-item dropdown-item" href="#"><i class="fas fa-edit me-2"></i>Edit Profile</a></li>
                            <li><a class="dropdown-menu-item dropdown-item" href="#"><i class="fas fa-envelope me-2"></i>Send Email</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-menu-item dropdown-item text-danger" href="#"><i class="fas fa-trash-alt me-2"></i>Delete Donor</a></li>
                        </ul>
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
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($pledges)): ?>
                                                <tr><td colspan="6" class="text-center py-3 text-muted">No pledges found.</td></tr>
                                            <?php else: ?>
                                                <?php foreach ($pledges as $pledge): ?>
                                                <tr>
                                                    <td>#<?php echo $pledge['id']; ?></td>
                                                    <td><?php echo formatDate($pledge['created_at']); ?></td>
                                                    <td class="fw-bold text-primary"><?php echo formatMoney($pledge['amount']); ?></td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $pledge['status'] == 'approved' ? 'success' : 'warning'; ?>">
                                                            <?php echo ucfirst($pledge['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
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
                                                    <td><?php echo htmlspecialchars($pledge['approver_name'] ?? 'System'); ?></td>
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
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($payments)): ?>
                                                <tr><td colspan="7" class="text-center py-3 text-muted">No payments found.</td></tr>
                                            <?php else: ?>
                                                <?php foreach ($payments as $pay): ?>
                                                <tr>
                                                    <td>#<?php echo $pay['id']; ?></td>
                                                    <td><?php echo formatDate($pay['display_date']); ?></td>
                                                    <td class="fw-bold text-success"><?php echo formatMoney($pay['amount']); ?></td>
                                                    <td><?php echo ucwords(str_replace('_', ' ', $pay['display_method'])); ?></td>
                                                    <td class="text-muted small"><?php echo htmlspecialchars($pay['display_ref'] ?? '-'); ?></td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $pay['status'] == 'approved' ? 'success' : 'warning'; ?>">
                                                            <?php echo ucfirst($pay['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($pay['approver_name'] ?? 'System'); ?></td>
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
                                                    <td>#<?php echo $plan['id']; ?></td>
                                                    <td><?php echo formatDate($plan['start_date']); ?></td>
                                                    <td><?php echo formatMoney($plan['total_amount']); ?></td>
                                                    <td><?php echo formatMoney($plan['monthly_amount']); ?></td>
                                                    <td>
                                                        <?php 
                                                            $progress = $plan['total_payments'] > 0 ? ($plan['payments_made'] / $plan['total_payments']) * 100 : 0;
                                                        ?>
                                                        <div class="d-flex align-items-center">
                                                            <div class="progress flex-grow-1 me-2" style="height: 6px;">
                                                                <div class="progress-bar bg-success" style="width: <?php echo $progress; ?>%"></div>
                                                            </div>
                                                            <small class="text-muted"><?php echo $plan['payments_made']; ?>/<?php echo $plan['total_payments']; ?></small>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <span class="status-badge bg-<?php echo $plan['status'] == 'active' ? 'success' : 'secondary'; ?>">
                                                            <?php echo ucfirst($plan['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <a href="payment-plans.php?id=<?php echo $plan['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                            View
                                                        </a>
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
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($calls)): ?>
                                                <tr><td colspan="6" class="text-center py-3 text-muted">No call history.</td></tr>
                                            <?php else: ?>
                                                <?php foreach ($calls as $call): ?>
                                                <tr>
                                                    <td><?php echo formatDateTime($call['call_started_at']); ?></td>
                                                    <td><?php echo htmlspecialchars($call['agent_name'] ?? 'Unknown'); ?></td>
                                                    <td>
                                                        <span class="badge bg-light text-dark border">
                                                            <?php echo ucwords(str_replace('_', ' ', $call['outcome'])); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo $call['duration_seconds'] ? gmdate("i:s", (int)$call['duration_seconds']) : '-'; ?></td>
                                                    <td><?php echo ucwords(str_replace('_', ' ', $call['conversation_stage'])); ?></td>
                                                    <td class="text-truncate" style="max-width: 200px;" title="<?php echo htmlspecialchars($call['notes']); ?>">
                                                        <?php echo htmlspecialchars($call['notes'] ?? '-'); ?>
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

                    <!-- 6. System & Audit -->
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
</body>
</html>
