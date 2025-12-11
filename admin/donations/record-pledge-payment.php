<?php
// admin/donations/record-pledge-payment.php
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

require_login();
// Allow both admin and registrar roles
$user = current_user();
$role = strtolower(trim((string)($user['role'] ?? '')));
if (!in_array($role, ['admin', 'registrar'], true)) {
    header('Location: ../error/403.php');
    exit;
}
$page_title = 'Record Pledge Payment';

$db = db();

// Check if table exists
$check = $db->query("SHOW TABLES LIKE 'pledge_payments'");
if ($check->num_rows === 0) {
    die("<div class='alert alert-danger m-4'>Error: Table 'pledge_payments' not found. Please run the migration SQL first.</div>");
}

// Donor Search
$search = $_GET['search'] ?? '';
$selected_donor_id = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0;

$donors = [];
if ($search || $selected_donor_id) {
    if ($selected_donor_id) {
        // Direct donor ID lookup
        $query = "SELECT id, name, phone, email, balance, total_paid, total_pledged FROM donors WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param('i', $selected_donor_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            // Check if donor has pledges
            $check_pledges = $db->prepare("SELECT COUNT(*) as count FROM pledges WHERE donor_id = ?");
            $check_pledges->bind_param('i', $row['id']);
            $check_pledges->execute();
            $pledge_result = $check_pledges->get_result()->fetch_assoc();
            $row['has_pledges'] = ($pledge_result['count'] ?? 0) > 0;
            $row['has_immediate_payments'] = (float)$row['total_paid'] > 0 && !$row['has_pledges'];
            $donors[] = $row;
        }
    } elseif ($search) {
        try {
            // Search by donor details OR pledge notes (reference number) OR payment references
            $term = "%$search%";
            
            // Get donor IDs from pledges with matching notes
            $pledge_donors_sql = "
                SELECT DISTINCT donor_id FROM pledges WHERE notes LIKE ?
                UNION
                SELECT DISTINCT donor_id FROM pledge_payments WHERE reference_number LIKE ?
            ";
            $stmt = $db->prepare($pledge_donors_sql);
            $stmt->bind_param('ss', $term, $term);
            $stmt->execute();
            $pledge_donor_ids = [];
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                if ($row['donor_id']) {
                    $pledge_donor_ids[] = (int)$row['donor_id'];
                }
            }
            
            // Get donor IDs from immediate payments (payments table)
            $payment_donors_sql = "
                SELECT DISTINCT donor_id FROM payments 
                WHERE (reference LIKE ? OR donor_name LIKE ? OR donor_phone LIKE ?)
                AND donor_id IS NOT NULL
            ";
            $stmt = $db->prepare($payment_donors_sql);
            $stmt->bind_param('sss', $term, $term, $term);
            $stmt->execute();
            $payment_donor_ids = [];
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                if ($row['donor_id']) {
                    $payment_donor_ids[] = (int)$row['donor_id'];
                }
            }
            
            // Combine all donor IDs
            $all_donor_ids = array_unique(array_merge($pledge_donor_ids, $payment_donor_ids));
            
            // Build donor query
            $donor_sql = "SELECT DISTINCT d.id, d.name, d.phone, d.email, d.balance, d.total_paid, d.total_pledged FROM donors d WHERE ";
            
            if (!empty($all_donor_ids)) {
                // Search by name/phone/email OR pledge/payment reference
                $placeholders = implode(',', array_fill(0, count($all_donor_ids), '?'));
                $donor_sql .= "(d.name LIKE ? OR d.phone LIKE ? OR d.email LIKE ? OR d.id IN ($placeholders))";
                
                $types = 'sss' . str_repeat('i', count($all_donor_ids));
                $params = array_merge([$term, $term, $term], $all_donor_ids);
            } else {
                // Only search by name/phone/email
                $donor_sql .= "(d.name LIKE ? OR d.phone LIKE ? OR d.email LIKE ?)";
                $types = 'sss';
                $params = [$term, $term, $term];
            }
            
            $donor_sql .= " LIMIT 20";
            $stmt = $db->prepare($donor_sql);
            
            // PHP 8.0 compatible binding
            $bind_params = [$types];
            $refs = [];
            foreach ($params as $key => $value) {
                $refs[$key] = $value;
                $bind_params[] = &$refs[$key];
            }
            call_user_func_array([$stmt, 'bind_param'], $bind_params);
            
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                // Check if donor has pledges or only immediate payments
                $donor_id = (int)$row['id'];
                $has_pledges = in_array($donor_id, $pledge_donor_ids);
                $has_payments = in_array($donor_id, $payment_donor_ids);
                
                // Add payment status flag
                $row['has_pledges'] = $has_pledges;
                $row['has_immediate_payments'] = $has_payments && !$has_pledges;
                
                $donors[] = $row;
            }
        } catch (Exception $e) {
            // Silently fail or log error, but don't crash page
            echo "<!-- Search Error: " . htmlspecialchars($e->getMessage()) . " -->";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $page_title; ?></title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        .donor-card {
            cursor: pointer;
            transition: all 0.2s;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 0.75rem;
        }
        .donor-card:hover {
            border-color: #0d6efd;
            background: #f8f9fa;
            transform: translateY(-2px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .donor-card.selected {
            border-color: #0d6efd;
            background: #e7f1ff;
            box-shadow: 0 2px 8px rgba(13,110,253,0.2);
        }
        .donor-card.paid-only {
            border-color: #ffc107;
            background: #fffbf0;
        }
        .donor-payment-notice {
            font-size: 0.75rem;
            color: #856404;
            background: #fff3cd;
            padding: 0.35rem 0.5rem;
            border-radius: 4px;
            margin-top: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.35rem;
        }
        .donor-payment-notice i {
            font-size: 0.7rem;
        }
        .pledge-row {
            cursor: pointer;
            transition: background 0.15s;
        }
        .pledge-row:hover {
            background: #f8f9fa;
        }
        .pledge-row.selected {
            background: #e7f1ff;
            border-left: 3px solid #0d6efd;
        }
        .section-title {
            font-size: 0.875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #e9ecef;
        }
        .step-indicator {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            background: #0d6efd;
            color: white;
            border-radius: 50%;
            font-weight: 600;
            font-size: 0.875rem;
            margin-right: 0.5rem;
            transition: all 0.3s;
        }
        .step-indicator.active {
            background: #0d6efd;
            box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.2);
        }
        .step-indicator.completed {
            background: #198754;
        }
        .form-group-custom {
            margin-bottom: 1.25rem;
        }
        .form-group-custom label {
            font-weight: 600;
            font-size: 0.875rem;
            color: #495057;
            margin-bottom: 0.5rem;
        }
        .form-control:focus, .form-select:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 0 0.2rem rgba(13,110,253,0.15);
        }
        .donor-list-container {
            max-height: 400px;
            overflow-y: auto;
        }
        
        /* Donor History Styles - Clean & Minimal */
        .donor-history-container {
            background: #fff;
            border-radius: 10px;
            padding: 1rem;
            margin-top: 1rem;
            border: 1px solid #e0e0e0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        .history-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid #f0f0f0;
            margin-bottom: 0.75rem;
        }
        .history-header h6 {
            margin: 0;
            font-size: 0.9rem;
            font-weight: 600;
            color: #333;
            flex: 1;
        }
        .history-header .btn-outline-primary {
            border-width: 1px;
            font-weight: 500;
        }
        .history-header .btn-outline-primary:hover {
            background: #1976d2;
            border-color: #1976d2;
            color: white;
        }
        .history-summary {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 0.75rem;
        }
        .summary-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.35rem 0.65rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        .summary-pill.pledged { background: #e3f2fd; color: #1565c0; }
        .summary-pill.paid { background: #e8f5e9; color: #2e7d32; }
        .summary-pill.balance { background: #ffebee; color: #c62828; }
        .summary-pill.pending { background: #fff8e1; color: #f57c00; }
        .summary-pill.voided { background: #f5f5f5; color: #757575; }
        .history-list {
            max-height: 220px;
            overflow-y: auto;
        }
        .history-item {
            display: flex;
            align-items: center;
            padding: 0.6rem 0;
            border-bottom: 1px solid #f5f5f5;
            gap: 0.75rem;
        }
        .history-item:last-child {
            border-bottom: none;
        }
        .history-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .history-dot.confirmed { background: #4caf50; }
        .history-dot.pending { background: #ff9800; }
        .history-dot.voided { background: #9e9e9e; }
        .history-dot.pledge { background: #2196f3; }
        .history-info {
            flex: 1;
            min-width: 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .history-amount {
            font-weight: 600;
            font-size: 0.85rem;
            color: #333;
        }
        .history-meta {
            font-size: 0.7rem;
            color: #888;
        }
        .history-date {
            font-size: 0.7rem;
            color: #999;
            white-space: nowrap;
        }
        .history-empty {
            text-align: center;
            padding: 1.5rem;
            color: #999;
        }
        .history-empty i {
            font-size: 1.5rem;
            opacity: 0.3;
            margin-bottom: 0.5rem;
            display: block;
        }
        .history-tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
        }
        .history-tab {
            padding: 0.4rem 0.8rem;
            border: none;
            background: #f5f5f5;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 500;
            color: #666;
            cursor: pointer;
            transition: all 0.15s;
        }
        .history-tab:hover {
            background: #e8e8e8;
        }
        .history-tab.active {
            background: #1976d2;
            color: white;
        }
        .progress-bar-mini {
            height: 3px;
            background: #e0e0e0;
            border-radius: 2px;
            overflow: hidden;
            width: 60px;
        }
        .progress-bar-mini .fill {
            height: 100%;
            background: #4caf50;
            border-radius: 2px;
        }
        
        /* Mobile Optimizations */
        @media (max-width: 991.98px) {
            .main-content {
                padding: 0.5rem !important;
            }
            .section-title {
                font-size: 0.75rem;
            }
            .step-indicator {
                width: 24px;
                height: 24px;
                font-size: 0.75rem;
            }
            .donor-card {
                padding: 0.75rem;
                margin-bottom: 0.5rem;
            }
            .donor-list-container {
                max-height: 250px;
            }
            .table {
                font-size: 0.875rem;
            }
            .form-group-custom {
                margin-bottom: 1rem;
            }
            .form-group-custom label {
                font-size: 0.8rem;
            }
            .history-list {
                max-height: 180px;
            }
        }
        
        @media (max-width: 575.98px) {
            .main-content {
                padding: 0.5rem !important;
            }
            h1 {
                font-size: 1.25rem !important;
            }
            .card-body {
                padding: 1rem;
            }
            .donor-card {
                padding: 0.625rem;
            }
            .section-title {
                font-size: 0.7rem;
                margin-bottom: 0.75rem;
            }
            .table {
                font-size: 0.75rem;
            }
            .table th, .table td {
                padding: 0.5rem 0.25rem;
            }
            .form-control, .form-select {
                font-size: 0.875rem;
                padding: 0.5rem;
            }
            .btn {
                font-size: 0.875rem;
                padding: 0.5rem 1rem;
            }
            .input-group-text {
                padding: 0.5rem;
                font-size: 0.875rem;
            }
            .table .hide-xs {
                display: none;
            }
            /* Clean history mobile */
            .donor-history-container {
                padding: 0.75rem;
                margin-top: 0.75rem;
            }
            .history-header h6 {
                font-size: 0.8rem;
            }
            .history-header .btn-outline-primary {
                font-size: 0.7rem;
                padding: 0.2rem 0.4rem;
            }
            .history-header .btn-outline-primary i {
                margin-right: 0.25rem;
            }
            .history-summary {
                gap: 0.5rem;
            }
            .summary-pill {
                padding: 0.25rem 0.5rem;
                font-size: 0.7rem;
            }
            .history-tabs {
                gap: 0.35rem;
            }
            .history-tab {
                padding: 0.35rem 0.6rem;
                font-size: 0.7rem;
            }
            .history-list {
                max-height: 150px;
            }
            .history-item {
                padding: 0.5rem 0;
            }
            .history-amount {
                font-size: 0.8rem;
            }
            .history-meta {
                font-size: 0.65rem;
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
            <div class="container-fluid p-3 p-md-4">
                <!-- Step Progress Indicator -->
                <div class="mb-4">
                    <div class="d-flex align-items-center justify-content-center gap-3">
                        <div class="d-flex align-items-center">
                            <div class="step-indicator" id="stepIndicator1">1</div>
                            <span class="small fw-bold text-muted" id="stepLabel1">Select Donor</span>
                        </div>
                        <div class="text-muted">→</div>
                        <div class="d-flex align-items-center">
                            <div class="step-indicator bg-secondary" id="stepIndicator2">2</div>
                            <span class="small text-muted" id="stepLabel2">Enter Payment</span>
                        </div>
                    </div>
                </div>

                <div class="row justify-content-center">
                    <!-- Step 1: Donor Selection -->
                    <div class="col-lg-8 col-xl-6" id="step1Container">
                        <div class="card shadow-sm border-0">
                            <div class="card-body">
                                <div class="section-title">
                                    <span class="step-indicator">1</span>
                                    Select Donor
                                </div>
                                
                                <form method="GET" class="mb-3">
                                    <div class="input-group">
                                        <input type="text" name="search" class="form-control" 
                                               placeholder="Search name, phone, or reference..." 
                                               value="<?php echo htmlspecialchars($search); ?>">
                                        <button class="btn btn-primary" type="submit">
                                            <i class="fas fa-search"></i>
                                        </button>
                                    </div>
                                    <small class="text-muted d-block mt-2">
                                        <i class="fas fa-info-circle me-1"></i>Search by donor info or pledge reference
                                    </small>
                                </form>
                                
                                <div class="donor-list-container">
                                    <?php if (empty($donors) && $search): ?>
                                        <div class="text-center py-3 py-md-4 text-muted">
                                            <i class="fas fa-search fa-2x mb-2 opacity-25"></i>
                                            <p class="small mb-0">No donors found</p>
                                        </div>
                                    <?php elseif (empty($donors) && !$selected_donor_id): ?>
                                        <div class="text-center py-3 py-md-4 text-muted">
                                            <i class="fas fa-users fa-2x mb-2 opacity-25"></i>
                                            <p class="small mb-0">Search to find donors</p>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($donors as $d): ?>
                                            <?php 
                                            $has_only_payments = !empty($d['has_immediate_payments']);
                                            $display_amount = $has_only_payments ? (float)($d['total_paid'] ?? 0) : (float)($d['balance'] ?? 0);
                                            $badge_color = $has_only_payments ? 'success' : 'danger';
                                            $badge_label = $has_only_payments ? 'Paid' : 'Balance';
                                            ?>
                                            <div class="donor-card <?php echo $selected_donor_id == $d['id'] ? 'selected' : ''; ?> <?php echo $has_only_payments ? 'paid-only' : ''; ?>" 
                                                 data-donor-id="<?php echo $d['id']; ?>"
                                                 onclick="selectDonor(<?php echo $d['id']; ?>, '<?php echo addslashes($d['name']); ?>')">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div class="flex-grow-1 me-2">
                                                        <div class="fw-bold mb-1"><?php echo htmlspecialchars($d['name']); ?></div>
                                                        <div class="small text-muted">
                                                            <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($d['phone']); ?>
                                                        </div>
                                                        <?php if ($has_only_payments): ?>
                                                        <div class="donor-payment-notice">
                                                            <i class="fas fa-info-circle"></i>
                                                            <span>This donor has already paid (no active pledges)</span>
                                                        </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="flex-shrink-0 text-end">
                                                        <div class="badge bg-<?php echo $badge_color; ?>">£<?php echo number_format($display_amount, 2); ?></div>
                                                        <?php if ($has_only_payments): ?>
                                                        <div class="small text-muted mt-1" style="font-size: 0.7rem;">Paid</div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Donor History Section - Clean Design -->
                                <div id="donorHistoryContainer" class="donor-history-container" style="display: none;">
                                    <div class="history-header">
                                        <h6><span id="historyDonorName">Donor</span>'s History</h6>
                                        <div class="d-flex align-items-center gap-2">
                                            <a href="#" id="viewProfileLink" class="btn btn-sm btn-outline-primary" target="_blank" style="font-size: 0.75rem; padding: 0.25rem 0.5rem;">
                                                <i class="fas fa-user me-1"></i>Profile
                                            </a>
                                            <button type="button" class="btn btn-sm btn-link text-muted p-0" onclick="hideHistory()">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <!-- Dynamic Summary Pills - Only shows relevant data -->
                                    <div class="history-summary" id="historySummary"></div>
                                    
                                    <!-- Simple Tabs -->
                                    <div class="history-tabs" id="historyTabs">
                                        <button class="history-tab active" onclick="switchHistoryTab('payments')">Payments</button>
                                        <button class="history-tab" onclick="switchHistoryTab('pledges')">Pledges</button>
                                    </div>
                                    
                                    <!-- Content -->
                                    <div class="history-list" id="historyContent">
                                        <div class="history-empty">
                                            <div class="spinner-border spinner-border-sm text-muted"></div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Step 1 Action Buttons -->
                                <div class="mt-4 pt-3 border-top text-end" id="step1Actions" style="display: none;">
                                    <button type="button" class="btn btn-primary px-4" id="btnNext">
                                        <span>Next: Enter Payment</span>
                                        <i class="fas fa-arrow-right ms-2"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Step 2: Payment Details -->
                    <div class="col-lg-10 col-xl-8" id="step2Container" style="display: none;">
                        <div class="card shadow-sm border-0">
                            <div class="card-body">
                                <div class="section-title">
                                    <span class="step-indicator">2</span>
                                    Select Pledge & Enter Payment
                                </div>
                                
                                <div class="alert alert-info mb-3">
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-user-circle fa-2x me-3"></i>
                                        <div>
                                            <strong>Selected Donor:</strong> <span id="selectedDonorNameTop" class="text-primary"></span><br>
                                            <small class="text-muted">Recording payment for this donor</small>
                                        </div>
                                    </div>
                                </div>
                                
                                <h6 class="mb-3">
                                    Active Pledges for <span id="selectedDonorName" class="text-primary fw-bold"></span>
                                </h6>
                                
                                <div class="table-responsive mb-3 mb-md-4">
                                    <table class="table table-sm table-hover align-middle">
                                        <thead class="table-light">
                                            <tr>
                                                <th width="30"></th>
                                                <th>Date</th>
                                                <th class="text-end">Amount</th>
                                                <th class="text-end">Remaining</th>
                                                <th class="hide-xs">Notes</th>
                                            </tr>
                                        </thead>
                                        <tbody id="pledgeListBody">
                                            <tr><td colspan="5" class="text-center py-3 text-muted">
                                                <div class="spinner-border spinner-border-sm me-2"></div>Loading...
                                            </td></tr>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <form id="paymentForm">
                                    <input type="hidden" name="donor_id" id="formDonorId">
                                    <input type="hidden" name="pledge_id" id="formPledgeId">
                                    
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <div class="form-group-custom">
                                                <label class="form-label">Payment Amount</label>
                                                <div class="input-group">
                                                    <span class="input-group-text">£</span>
                                                    <input type="number" step="0.01" name="amount" id="paymentAmount" 
                                                           class="form-control" placeholder="0.00" required>
                                                </div>
                                                <small class="text-muted">Max: £<span id="maxAmount" class="fw-bold">0.00</span></small>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group-custom">
                                                <label class="form-label">Payment Date</label>
                                                <input type="date" name="payment_date" class="form-control" 
                                                       value="<?php echo date('Y-m-d'); ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group-custom">
                                                <label class="form-label">Payment Method</label>
                                                <select name="payment_method" class="form-select" required>
                                                    <option value="">Select method...</option>
                                                    <option value="cash">Cash</option>
                                                    <option value="bank_transfer" selected>Bank Transfer</option>
                                                    <option value="card">Card</option>
                                                    <option value="cheque">Cheque</option>
                                                    <option value="other">Other</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group-custom">
                                                <label class="form-label">Reference Number <span class="text-muted">(Optional)</span></label>
                                                <input type="text" name="reference_number" class="form-control" 
                                                       placeholder="Receipt/transaction #">
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="form-group-custom">
                                                <label class="form-label">Payment Proof <span class="text-muted">(Optional)</span></label>
                                                <input type="file" name="payment_proof" class="form-control" 
                                                       accept="image/*,.pdf">
                                                <small class="text-muted">
                                                    <i class="fas fa-paperclip me-1"></i>Upload receipt or screenshot (helps speed up approval)
                                                </small>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="form-group-custom mb-0">
                                                <label class="form-label">Additional Notes <span class="text-muted">(Optional)</span></label>
                                                <textarea name="notes" class="form-control" rows="2" 
                                                          placeholder="Any additional information..."></textarea>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-3 mt-md-4 pt-3 border-top d-flex flex-column flex-sm-row justify-content-between gap-2">
                                        <button type="button" class="btn btn-light order-2 order-sm-1" id="btnBack">
                                            <i class="fas fa-arrow-left me-2"></i>Back to Donors
                                        </button>
                                        <button type="submit" class="btn btn-primary px-4 order-1 order-sm-2" id="btnSubmit">
                                            <i class="fas fa-check me-2"></i>
                                            <span class="d-none d-sm-inline">Submit for Approval</span>
                                            <span class="d-inline d-sm-none">Submit</span>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
let selectedDonorId = null;
let selectedDonorName = '';

function selectDonor(id, name) {
    selectedDonorId = id;
    selectedDonorName = name;
    
    document.getElementById('formDonorId').value = id;
    document.getElementById('selectedDonorName').textContent = name;
    document.getElementById('selectedDonorNameTop').textContent = name;
    document.getElementById('historyDonorName').textContent = name;
    
    // Update profile link
    document.getElementById('viewProfileLink').href = `../donor-management/view-donor.php?id=${id}`;
    
    // Check if donor card has paid-only class
    const donorCard = event.target.closest('.donor-card');
    const hasOnlyPayments = donorCard && donorCard.classList.contains('paid-only');
    
    // Show next button only if donor has pledges
    if (!hasOnlyPayments) {
        document.getElementById('step1Actions').style.display = 'block';
    } else {
        document.getElementById('step1Actions').style.display = 'none';
    }
    
    // Highlight selected card
    document.querySelectorAll('.donor-card').forEach(c => c.classList.remove('selected'));
    donorCard.classList.add('selected');
    
    // Load and show donor history
    loadDonorHistory(id);
}

let donorHistoryData = null;
let currentHistoryTab = 'payments';

function loadDonorHistory(donorId) {
    const container = document.getElementById('donorHistoryContainer');
    const content = document.getElementById('historyContent');
    
    container.style.display = 'block';
    content.innerHTML = `<div class="history-empty"><div class="spinner-border spinner-border-sm text-muted"></div></div>`;
    
    fetch(`get-donor-history.php?donor_id=${donorId}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                content.innerHTML = `<div class="history-empty"><i class="fas fa-exclamation-circle"></i><p class="small">${data.error}</p></div>`;
                return;
            }
            
            donorHistoryData = data;
            renderSummaryPills(data.stats);
            switchHistoryTab('payments');
        })
        .catch(err => {
            console.error(err);
            content.innerHTML = `<div class="history-empty"><i class="fas fa-exclamation-circle"></i><p class="small">Error loading</p></div>`;
        });
}

function renderSummaryPills(stats) {
    const container = document.getElementById('historySummary');
    let html = '';
    
    // Only show pills with actual data
    if (stats.total_pledged > 0) {
        html += `<span class="summary-pill pledged">£${stats.total_pledged.toFixed(0)} pledged</span>`;
    }
    if (stats.total_paid > 0) {
        html += `<span class="summary-pill paid">£${stats.total_paid.toFixed(0)} paid</span>`;
    }
    if (stats.balance > 0) {
        html += `<span class="summary-pill balance">£${stats.balance.toFixed(0)} due</span>`;
    }
    if (stats.pending_payments > 0) {
        html += `<span class="summary-pill pending">${stats.pending_payments} pending</span>`;
    }
    if (stats.voided_payments > 0) {
        html += `<span class="summary-pill voided">${stats.voided_payments} voided</span>`;
    }
    
    // If no data at all, show a simple message
    if (!html) {
        html = `<span class="summary-pill" style="background:#f5f5f5;color:#999;">New donor</span>`;
    }
    
    container.innerHTML = html;
}

function switchHistoryTab(tab) {
    currentHistoryTab = tab;
    
    // Update tab buttons
    document.querySelectorAll('.history-tab').forEach(btn => {
        btn.classList.toggle('active', btn.textContent.toLowerCase().includes(tab));
    });
    
    if (!donorHistoryData) return;
    
    if (tab === 'payments') {
        renderPaymentsList();
    } else {
        renderPledgesList();
    }
}

function renderPaymentsList() {
    const content = document.getElementById('historyContent');
    const allPayments = [];
    
    // Combine pledge payments
    donorHistoryData.pledge_payments.forEach(p => {
        allPayments.push({
            amount: parseFloat(p.amount),
            status: p.status,
            date: p.payment_date || p.created_at,
            method: p.payment_method,
            ref: p.pledge_reference || p.reference_number
        });
    });
    
    // Combine immediate payments
    donorHistoryData.immediate_payments.forEach(p => {
        allPayments.push({
            amount: parseFloat(p.amount),
            status: p.status === 'approved' ? 'confirmed' : p.status,
            date: p.payment_date,
            method: p.payment_method,
            ref: p.reference
        });
    });
    
    // Sort by date
    allPayments.sort((a, b) => new Date(b.date) - new Date(a.date));
    
    if (allPayments.length === 0) {
        content.innerHTML = `<div class="history-empty"><i class="fas fa-receipt"></i><p class="small">No payments yet</p></div>`;
        return;
    }
    
    let html = '';
    allPayments.forEach(p => {
        const statusClass = p.status === 'confirmed' ? 'confirmed' : p.status === 'pending' ? 'pending' : 'voided';
        html += `
            <div class="history-item">
                <div class="history-dot ${statusClass}"></div>
                <div class="history-info">
                    <div>
                        <span class="history-amount">£${p.amount.toFixed(2)}</span>
                        <span class="history-meta">${formatMethod(p.method)}${p.ref ? ' • ' + p.ref : ''}</span>
                    </div>
                    <span class="history-date">${formatDate(p.date)}</span>
                </div>
            </div>
        `;
    });
    
    content.innerHTML = html;
}

function renderPledgesList() {
    const content = document.getElementById('historyContent');
    const pledges = donorHistoryData.pledges;
    
    if (pledges.length === 0) {
        content.innerHTML = `<div class="history-empty"><i class="fas fa-hand-holding-usd"></i><p class="small">No pledges yet</p></div>`;
        return;
    }
    
    let html = '';
    pledges.forEach(p => {
        const amount = parseFloat(p.amount);
        const progress = p.progress_percent;
        const statusClass = p.status === 'approved' ? 'pledge' : p.status === 'pending' ? 'pending' : 'voided';
        
        html += `
            <div class="history-item">
                <div class="history-dot ${statusClass}"></div>
                <div class="history-info">
                    <div style="flex:1">
                        <span class="history-amount">£${amount.toFixed(2)}</span>
                        ${p.notes ? `<span class="history-meta">Ref: ${p.notes}</span>` : ''}
                        ${p.status === 'approved' && progress < 100 ? `
                            <div class="progress-bar-mini mt-1">
                                <div class="fill" style="width:${progress}%"></div>
                            </div>
                        ` : ''}
                        ${p.status === 'approved' && progress >= 100 ? `<span class="history-meta" style="color:#4caf50">✓ Paid</span>` : ''}
                    </div>
                    <span class="history-date">${formatDate(p.created_at)}</span>
                </div>
            </div>
        `;
    });
    
    content.innerHTML = html;
}

function formatMethod(method) {
    const labels = { 'cash': 'Cash', 'bank': 'Bank', 'bank_transfer': 'Bank', 'card': 'Card', 'cheque': 'Cheque' };
    return labels[method] || method || '';
}

function formatDate(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr);
    const now = new Date();
    const diff = Math.floor((now - d) / (1000 * 60 * 60 * 24));
    if (diff === 0) return 'Today';
    if (diff === 1) return 'Yesterday';
    if (diff < 7) return `${diff}d`;
    return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
}

function hideHistory() {
    document.getElementById('donorHistoryContainer').style.display = 'none';
}

// Next button: Go to Step 2
document.getElementById('btnNext').addEventListener('click', function() {
    if (!selectedDonorId) {
        alert('Please select a donor first');
        return;
    }
    
    // Hide Step 1
    document.getElementById('step1Container').style.display = 'none';
    
    // Show Step 2
    document.getElementById('step2Container').style.display = 'block';
    
    // Update progress indicators
    document.getElementById('stepIndicator1').classList.remove('active');
    document.getElementById('stepIndicator1').classList.add('completed');
    document.getElementById('stepLabel1').classList.remove('text-muted');
    document.getElementById('stepLabel1').classList.add('text-success');
    
    document.getElementById('stepIndicator2').classList.remove('bg-secondary');
    document.getElementById('stepIndicator2').classList.add('active');
    document.getElementById('stepLabel2').classList.remove('text-muted');
    document.getElementById('stepLabel2').classList.add('fw-bold');
    
    // Fetch pledges
    fetchPledges(selectedDonorId);
    
    // Scroll to top
    window.scrollTo({ top: 0, behavior: 'smooth' });
});

// Back button: Go to Step 1
document.getElementById('btnBack').addEventListener('click', function() {
    // Show Step 1
    document.getElementById('step1Container').style.display = 'block';
    
    // Hide Step 2
    document.getElementById('step2Container').style.display = 'none';
    
    // Update progress indicators
    document.getElementById('stepIndicator1').classList.add('active');
    document.getElementById('stepIndicator1').classList.remove('completed');
    document.getElementById('stepLabel1').classList.add('text-muted');
    document.getElementById('stepLabel1').classList.remove('text-success');
    
    document.getElementById('stepIndicator2').classList.add('bg-secondary');
    document.getElementById('stepIndicator2').classList.remove('active');
    document.getElementById('stepLabel2').classList.add('text-muted');
    document.getElementById('stepLabel2').classList.remove('fw-bold');
    
    // Scroll to top
    window.scrollTo({ top: 0, behavior: 'smooth' });
});

function fetchPledges(donorId) {
    const tbody = document.getElementById('pledgeListBody');
    tbody.innerHTML = `
        <tr>
            <td colspan="5" class="text-center py-3">
                <div class="spinner-border spinner-border-sm text-primary me-2"></div>
                <span class="text-muted small">Loading pledges...</span>
            </td>
        </tr>
    `;
    
    fetch(`get-donor-pledges.php?donor_id=${donorId}`)
    .then(r => r.json())
    .then(res => {
        tbody.innerHTML = '';
        if (res.success && res.pledges.length > 0) {
            res.pledges.forEach(p => {
                const tr = document.createElement('tr');
                tr.className = 'pledge-row';
                tr.onclick = () => selectPledge(p.id, p.remaining);
                
                // Truncate notes for mobile
                const notes = p.notes || '—';
                const displayNotes = notes.length > 20 ? notes.substring(0, 20) + '...' : notes;
                
                tr.innerHTML = `
                    <td class="text-center">
                        <input type="radio" name="pledge_select" value="${p.id}" id="pledge_${p.id}" class="form-check-input">
                    </td>
                    <td><small class="text-nowrap">${p.date}</small></td>
                    <td class="text-end text-nowrap"><small>£${p.amount.toFixed(2)}</small></td>
                    <td class="text-end text-nowrap"><strong class="text-danger">£${p.remaining.toFixed(2)}</strong></td>
                    <td class="hide-xs"><small class="text-muted">${displayNotes}</small></td>
                `;
                tbody.appendChild(tr);
            });
            // Auto-select first one
            selectPledge(res.pledges[0].id, res.pledges[0].remaining);
        } else {
            tbody.innerHTML = `
                <tr>
                    <td colspan="5" class="text-center py-3 py-md-4">
                        <i class="fas fa-inbox fa-2x mb-2 text-muted opacity-25 d-block"></i>
                        <p class="text-muted small mb-0">No active unpaid pledges</p>
                    </td>
                </tr>
            `;
            document.getElementById('btnSubmit').disabled = true;
        }
    })
    .catch(err => {
        tbody.innerHTML = `
            <tr>
                <td colspan="5" class="text-center py-3 text-danger">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <span class="small">Error loading pledges</span>
                </td>
            </tr>
        `;
        console.error(err);
    });
}

function selectPledge(id, remaining) {
    document.getElementById('formPledgeId').value = id;
    document.getElementById('paymentAmount').value = remaining.toFixed(2);
    document.getElementById('paymentAmount').max = remaining;
    document.getElementById('maxAmount').textContent = remaining.toFixed(2);
    document.getElementById('btnSubmit').disabled = false;
    
    // Highlight row and check radio
    document.querySelectorAll('.pledge-row').forEach(r => r.classList.remove('selected'));
    const radio = document.getElementById(`pledge_${id}`);
    radio.checked = true;
    radio.closest('tr').classList.add('selected');
}

document.getElementById('paymentForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    if (!document.getElementById('formPledgeId').value) {
        alert('Please select a pledge first');
        return;
    }
    
    // Submit immediately without confirmation
    const formData = new FormData(this);
    const btn = document.getElementById('btnSubmit');
    const originalHTML = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Submitting...';
    
    fetch('save-pledge-payment.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(res => {
        if(res.success) {
            // Show success alert
            const alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-success alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3';
            alertDiv.style.zIndex = '9999';
            alertDiv.innerHTML = `
                <i class="fas fa-check-circle me-2"></i>
                <strong>Success!</strong> Payment submitted for approval.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            document.body.appendChild(alertDiv);
            
            setTimeout(() => location.reload(), 1500);
        } else {
            alert('Error: ' + res.message);
            btn.disabled = false;
            btn.innerHTML = originalHTML;
        }
    })
    .catch(err => {
        console.error(err);
        alert('An error occurred. Please try again.');
        btn.disabled = false;
        btn.innerHTML = originalHTML;
    });
});

<?php if($selected_donor_id): ?>
    // Auto-select if loaded from URL
    <?php 
    $name = '';
    foreach($donors as $d) { if($d['id'] == $selected_donor_id) $name = $d['name']; } 
    ?>
    selectDonor(<?php echo $selected_donor_id; ?>, '<?php echo addslashes($name); ?>');
<?php endif; ?>
</script>
</body>
</html>

