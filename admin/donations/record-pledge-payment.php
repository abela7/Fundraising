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
        $query = "SELECT id, name, phone, email, balance FROM donors WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param('i', $selected_donor_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $donors[] = $row;
        }
    } elseif ($search) {
        try {
            // Search by donor details OR pledge notes (reference number)
            $term = "%$search%";
            
            // Get donor IDs from pledges with matching notes OR payment references
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
                $pledge_donor_ids[] = (int)$row['donor_id'];
            }
            
            // Build donor query
            $donor_sql = "SELECT DISTINCT d.id, d.name, d.phone, d.email, d.balance FROM donors d WHERE ";
            
            if (!empty($pledge_donor_ids)) {
                // Search by name/phone/email OR pledge reference
                $placeholders = implode(',', array_fill(0, count($pledge_donor_ids), '?'));
                $donor_sql .= "(d.name LIKE ? OR d.phone LIKE ? OR d.email LIKE ? OR d.id IN ($placeholders))";
                
                $types = 'sss' . str_repeat('i', count($pledge_donor_ids));
                $params = array_merge([$term, $term, $term], $pledge_donor_ids);
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
            // Create a separate array for references to ensure stability
            $refs = [];
            foreach ($params as $key => $value) {
                $refs[$key] = $value;
                $bind_params[] = &$refs[$key];
            }
            call_user_func_array([$stmt, 'bind_param'], $bind_params);
            
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $donors[] = $row;
            }
        } catch (Exception $e) {
            // Silently fail or log error, but don't crash page
            // For admin debugging, maybe echo in comment
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
        
        /* Donor History Styles */
        .donor-history-container {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 12px;
            padding: 1rem;
            margin-top: 1rem;
            border: 1px solid #dee2e6;
        }
        .history-stat-card {
            background: white;
            border-radius: 8px;
            padding: 0.75rem;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            height: 100%;
        }
        .history-stat-card .stat-value {
            font-size: 1.25rem;
            font-weight: 700;
        }
        .history-stat-card .stat-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            margin-top: 0.25rem;
        }
        .history-timeline {
            max-height: 300px;
            overflow-y: auto;
        }
        .timeline-item {
            display: flex;
            padding: 0.5rem 0;
            border-bottom: 1px solid #e9ecef;
            font-size: 0.8rem;
        }
        .timeline-item:last-child {
            border-bottom: none;
        }
        .timeline-icon {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-right: 0.75rem;
            font-size: 0.7rem;
        }
        .timeline-icon.confirmed { background: #d1e7dd; color: #0f5132; }
        .timeline-icon.pending { background: #fff3cd; color: #664d03; }
        .timeline-icon.voided { background: #f8d7da; color: #842029; }
        .timeline-icon.pledge { background: #cfe2ff; color: #084298; }
        .timeline-content {
            flex-grow: 1;
            min-width: 0;
        }
        .timeline-amount {
            font-weight: 600;
        }
        .pledge-progress-mini {
            height: 4px;
            background: #e9ecef;
            border-radius: 2px;
            overflow: hidden;
            margin-top: 0.25rem;
        }
        .pledge-progress-mini .bar {
            height: 100%;
            background: linear-gradient(90deg, #198754, #20c997);
            border-radius: 2px;
            transition: width 0.3s;
        }
        .history-badge {
            font-size: 0.65rem;
            padding: 0.2rem 0.4rem;
            border-radius: 3px;
        }
        .no-history-message {
            text-align: center;
            padding: 2rem 1rem;
            color: #6c757d;
        }
        .no-history-message i {
            font-size: 2rem;
            opacity: 0.3;
            margin-bottom: 0.5rem;
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
            .history-stat-card {
                padding: 0.5rem;
            }
            .history-stat-card .stat-value {
                font-size: 1rem;
            }
            .history-stat-card .stat-label {
                font-size: 0.6rem;
            }
            .history-timeline {
                max-height: 250px;
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
            /* Hide less important columns on very small screens */
            .table .hide-xs {
                display: none;
            }
            /* History section mobile */
            .donor-history-container {
                padding: 0.75rem;
                margin-top: 0.75rem;
            }
            .donor-history-container h6 {
                font-size: 0.85rem;
            }
            .history-stat-card {
                padding: 0.4rem;
            }
            .history-stat-card .stat-value {
                font-size: 0.9rem;
            }
            .history-stat-card .stat-label {
                font-size: 0.55rem;
            }
            .history-timeline {
                max-height: 200px;
            }
            .timeline-item {
                padding: 0.4rem 0;
            }
            .timeline-icon {
                width: 24px;
                height: 24px;
                font-size: 0.6rem;
                margin-right: 0.5rem;
            }
            .timeline-amount {
                font-size: 0.85rem;
            }
            .nav-tabs .nav-link {
                padding: 0.4rem 0.5rem;
                font-size: 0.75rem;
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
                                            <div class="donor-card <?php echo $selected_donor_id == $d['id'] ? 'selected' : ''; ?>" 
                                                 data-donor-id="<?php echo $d['id']; ?>"
                                                 onclick="selectDonor(<?php echo $d['id']; ?>, '<?php echo addslashes($d['name']); ?>')">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div class="flex-grow-1 me-2">
                                                        <div class="fw-bold mb-1"><?php echo htmlspecialchars($d['name']); ?></div>
                                                        <div class="small text-muted">
                                                            <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($d['phone']); ?>
                                                        </div>
                                                    </div>
                                                    <div class="flex-shrink-0">
                                                        <span class="badge bg-danger">£<?php echo number_format($d['balance'], 2); ?></span>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Donor History Section -->
                                <div id="donorHistoryContainer" class="donor-history-container" style="display: none;">
                                    <div class="d-flex align-items-center justify-content-between mb-3">
                                        <h6 class="mb-0">
                                            <i class="fas fa-history text-primary me-2"></i>
                                            <span id="historyDonorName">Donor</span>'s History
                                        </h6>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="hideHistory()">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                    
                                    <!-- Stats Summary -->
                                    <div class="row g-2 mb-3" id="historyStats">
                                        <div class="col-4 col-md-2">
                                            <div class="history-stat-card">
                                                <div class="stat-value text-primary" id="statPledged">£0</div>
                                                <div class="stat-label">Pledged</div>
                                            </div>
                                        </div>
                                        <div class="col-4 col-md-2">
                                            <div class="history-stat-card">
                                                <div class="stat-value text-success" id="statPaid">£0</div>
                                                <div class="stat-label">Paid</div>
                                            </div>
                                        </div>
                                        <div class="col-4 col-md-2">
                                            <div class="history-stat-card">
                                                <div class="stat-value text-danger" id="statBalance">£0</div>
                                                <div class="stat-label">Balance</div>
                                            </div>
                                        </div>
                                        <div class="col-4 col-md-2">
                                            <div class="history-stat-card">
                                                <div class="stat-value text-success" id="statConfirmed">0</div>
                                                <div class="stat-label">Confirmed</div>
                                            </div>
                                        </div>
                                        <div class="col-4 col-md-2">
                                            <div class="history-stat-card">
                                                <div class="stat-value text-warning" id="statPending">0</div>
                                                <div class="stat-label">Pending</div>
                                            </div>
                                        </div>
                                        <div class="col-4 col-md-2">
                                            <div class="history-stat-card">
                                                <div class="stat-value text-secondary" id="statVoided">0</div>
                                                <div class="stat-label">Voided</div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Tabs for History -->
                                    <ul class="nav nav-tabs nav-fill mb-2" role="tablist" style="font-size: 0.8rem;">
                                        <li class="nav-item">
                                            <button class="nav-link active py-2" data-bs-toggle="tab" data-bs-target="#tabPayments">
                                                <i class="fas fa-money-bill-wave me-1 d-none d-sm-inline"></i>Payments
                                            </button>
                                        </li>
                                        <li class="nav-item">
                                            <button class="nav-link py-2" data-bs-toggle="tab" data-bs-target="#tabPledges">
                                                <i class="fas fa-hand-holding-usd me-1 d-none d-sm-inline"></i>Pledges
                                            </button>
                                        </li>
                                    </ul>
                                    
                                    <div class="tab-content">
                                        <!-- Payments Tab -->
                                        <div class="tab-pane fade show active" id="tabPayments">
                                            <div class="history-timeline" id="paymentsTimeline">
                                                <div class="text-center py-3">
                                                    <div class="spinner-border spinner-border-sm text-primary"></div>
                                                    <small class="d-block text-muted mt-1">Loading...</small>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Pledges Tab -->
                                        <div class="tab-pane fade" id="tabPledges">
                                            <div class="history-timeline" id="pledgesTimeline">
                                                <div class="text-center py-3">
                                                    <div class="spinner-border spinner-border-sm text-primary"></div>
                                                    <small class="d-block text-muted mt-1">Loading...</small>
                                                </div>
                                            </div>
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
                                                    <option value="bank_transfer">Bank Transfer</option>
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
    
    // Show next button
    document.getElementById('step1Actions').style.display = 'block';
    
    // Highlight selected card
    document.querySelectorAll('.donor-card').forEach(c => c.classList.remove('selected'));
    event.target.closest('.donor-card').classList.add('selected');
    
    // Load and show donor history
    loadDonorHistory(id);
}

function loadDonorHistory(donorId) {
    const container = document.getElementById('donorHistoryContainer');
    const paymentsTimeline = document.getElementById('paymentsTimeline');
    const pledgesTimeline = document.getElementById('pledgesTimeline');
    
    // Show container with loading state
    container.style.display = 'block';
    paymentsTimeline.innerHTML = `
        <div class="text-center py-3">
            <div class="spinner-border spinner-border-sm text-primary"></div>
            <small class="d-block text-muted mt-1">Loading payments...</small>
        </div>
    `;
    pledgesTimeline.innerHTML = `
        <div class="text-center py-3">
            <div class="spinner-border spinner-border-sm text-primary"></div>
            <small class="d-block text-muted mt-1">Loading pledges...</small>
        </div>
    `;
    
    fetch(`get-donor-history.php?donor_id=${donorId}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                paymentsTimeline.innerHTML = `<div class="text-center text-danger py-3"><i class="fas fa-exclamation-circle"></i> ${data.error}</div>`;
                return;
            }
            
            // Update stats
            const stats = data.stats;
            document.getElementById('statPledged').textContent = '£' + stats.total_pledged.toFixed(0);
            document.getElementById('statPaid').textContent = '£' + stats.total_paid.toFixed(0);
            document.getElementById('statBalance').textContent = '£' + stats.balance.toFixed(0);
            document.getElementById('statConfirmed').textContent = stats.confirmed_payments;
            document.getElementById('statPending').textContent = stats.pending_payments;
            document.getElementById('statVoided').textContent = stats.voided_payments;
            
            // Render payments timeline
            renderPaymentsTimeline(data.pledge_payments, data.immediate_payments, paymentsTimeline);
            
            // Render pledges timeline
            renderPledgesTimeline(data.pledges, pledgesTimeline);
        })
        .catch(err => {
            console.error(err);
            paymentsTimeline.innerHTML = `<div class="text-center text-danger py-3"><i class="fas fa-exclamation-circle"></i> Error loading history</div>`;
        });
}

function renderPaymentsTimeline(pledgePayments, immediatePayments, container) {
    // Combine and sort all payments by date
    const allPayments = [];
    
    pledgePayments.forEach(p => {
        allPayments.push({
            type: 'pledge_payment',
            amount: parseFloat(p.amount),
            status: p.status,
            date: p.payment_date || p.created_at,
            method: p.payment_method,
            reference: p.pledge_reference || p.reference_number,
            notes: p.notes
        });
    });
    
    immediatePayments.forEach(p => {
        allPayments.push({
            type: 'immediate_payment',
            amount: parseFloat(p.amount),
            status: p.status === 'approved' ? 'confirmed' : p.status,
            date: p.payment_date,
            method: p.payment_method,
            reference: p.reference,
            notes: ''
        });
    });
    
    // Sort by date descending
    allPayments.sort((a, b) => new Date(b.date) - new Date(a.date));
    
    if (allPayments.length === 0) {
        container.innerHTML = `
            <div class="no-history-message">
                <i class="fas fa-receipt d-block"></i>
                <p class="small mb-0">No payment history yet</p>
            </div>
        `;
        return;
    }
    
    let html = '';
    allPayments.forEach(p => {
        const statusClass = p.status === 'confirmed' ? 'confirmed' : 
                           p.status === 'pending' ? 'pending' : 'voided';
        const statusIcon = p.status === 'confirmed' ? 'fa-check' : 
                          p.status === 'pending' ? 'fa-clock' : 'fa-times';
        const statusBadge = p.status === 'confirmed' ? 'bg-success' : 
                           p.status === 'pending' ? 'bg-warning text-dark' : 'bg-secondary';
        const typeLabel = p.type === 'pledge_payment' ? 'Pledge Payment' : 'Direct Payment';
        const methodIcon = getMethodIcon(p.method);
        
        html += `
            <div class="timeline-item">
                <div class="timeline-icon ${statusClass}">
                    <i class="fas ${statusIcon}"></i>
                </div>
                <div class="timeline-content">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <span class="timeline-amount">£${p.amount.toFixed(2)}</span>
                            <span class="history-badge ${statusBadge} ms-1">${p.status}</span>
                        </div>
                        <small class="text-muted">${formatDate(p.date)}</small>
                    </div>
                    <div class="d-flex flex-wrap gap-1 mt-1">
                        <small class="text-muted">
                            <i class="fas ${methodIcon} me-1"></i>${formatMethod(p.method)}
                        </small>
                        ${p.reference ? `<small class="text-muted">• Ref: ${p.reference}</small>` : ''}
                    </div>
                </div>
            </div>
        `;
    });
    
    container.innerHTML = html;
}

function renderPledgesTimeline(pledges, container) {
    if (pledges.length === 0) {
        container.innerHTML = `
            <div class="no-history-message">
                <i class="fas fa-hand-holding-usd d-block"></i>
                <p class="small mb-0">No pledges yet</p>
            </div>
        `;
        return;
    }
    
    let html = '';
    pledges.forEach(p => {
        const amount = parseFloat(p.amount);
        const paid = parseFloat(p.paid_amount);
        const remaining = parseFloat(p.remaining);
        const progress = p.progress_percent;
        
        const statusClass = p.status === 'approved' ? 'pledge' : 
                           p.status === 'pending' ? 'pending' : 'voided';
        const statusBadge = p.status === 'approved' ? 'bg-primary' : 
                           p.status === 'pending' ? 'bg-warning text-dark' : 'bg-secondary';
        
        let progressStatus = '';
        if (p.status === 'approved') {
            if (progress >= 100) {
                progressStatus = '<span class="text-success"><i class="fas fa-check-circle"></i> Fully Paid</span>';
            } else if (progress > 0) {
                progressStatus = `<span class="text-info">${progress}% paid</span>`;
            } else {
                progressStatus = '<span class="text-warning">Not started</span>';
            }
        }
        
        html += `
            <div class="timeline-item">
                <div class="timeline-icon ${statusClass}">
                    <i class="fas fa-hand-holding-usd"></i>
                </div>
                <div class="timeline-content">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <span class="timeline-amount">£${amount.toFixed(2)}</span>
                            <span class="history-badge ${statusBadge} ms-1">${p.status}</span>
                        </div>
                        <small class="text-muted">${formatDate(p.created_at)}</small>
                    </div>
                    ${p.status === 'approved' ? `
                        <div class="pledge-progress-mini mt-1">
                            <div class="bar" style="width: ${progress}%"></div>
                        </div>
                        <div class="d-flex justify-content-between mt-1">
                            <small class="text-muted">Paid: £${paid.toFixed(2)}</small>
                            <small>${progressStatus}</small>
                        </div>
                    ` : ''}
                    ${p.notes ? `<small class="text-muted d-block mt-1">Ref: ${p.notes}</small>` : ''}
                </div>
            </div>
        `;
    });
    
    container.innerHTML = html;
}

function getMethodIcon(method) {
    const icons = {
        'cash': 'fa-money-bill',
        'bank': 'fa-university',
        'bank_transfer': 'fa-university',
        'card': 'fa-credit-card',
        'cheque': 'fa-money-check',
        'other': 'fa-receipt'
    };
    return icons[method] || 'fa-receipt';
}

function formatMethod(method) {
    const labels = {
        'cash': 'Cash',
        'bank': 'Bank',
        'bank_transfer': 'Bank Transfer',
        'card': 'Card',
        'cheque': 'Cheque',
        'other': 'Other'
    };
    return labels[method] || method || 'Unknown';
}

function formatDate(dateStr) {
    if (!dateStr) return 'N/A';
    const d = new Date(dateStr);
    const now = new Date();
    const diffDays = Math.floor((now - d) / (1000 * 60 * 60 * 24));
    
    if (diffDays === 0) return 'Today';
    if (diffDays === 1) return 'Yesterday';
    if (diffDays < 7) return `${diffDays}d ago`;
    
    return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: '2-digit' });
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
    
    if (!confirm('Submit this payment for approval?\n\nThe payment will be reviewed by an admin before being finalized.')) {
        return;
    }
    
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

