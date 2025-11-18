<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

// Set timezone to London
date_default_timezone_set('Europe/London');

try {
    $db = db();
    $user_id = (int)$_SESSION['user']['id'];
    
    // Get parameters
    $session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
    $donor_id = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0;
    $queue_id = isset($_GET['queue_id']) ? (int)$_GET['queue_id'] : 0;
    
    if (!$donor_id || !$queue_id) {
        header('Location: index.php');
        exit;
    }
    
    // Update session status to 'connected' if not already
    if ($session_id > 0) {
        $update_session = "UPDATE call_center_sessions SET conversation_stage = 'connected' WHERE id = ? AND conversation_stage = 'no_connection'";
        $stmt = $db->prepare($update_session);
        if ($stmt) {
            $stmt->bind_param('i', $session_id);
            $stmt->execute();
            $stmt->close();
        }
    }
    
    // Get donor and pledge information
    $donor_query = "
        SELECT d.name, d.phone, d.balance, d.city,
               COALESCE(p.amount, 0) as pledge_amount, 
               p.created_at as pledge_date,
               c.name as church_name,
               COALESCE(
                    (SELECT name FROM users WHERE id = d.registered_by_user_id LIMIT 1),
                    (SELECT u.name FROM pledges p2 JOIN users u ON p2.created_by_user_id = u.id WHERE p2.donor_id = d.id ORDER BY p2.created_at DESC LIMIT 1),
                    'Unknown'
                ) as registrar_name
        FROM donors d
        LEFT JOIN pledges p ON d.id = p.donor_id AND p.status = 'approved'
        LEFT JOIN churches c ON d.church_id = c.id
        WHERE d.id = ? 
        ORDER BY p.created_at DESC 
        LIMIT 1
    ";
    
    $stmt = $db->prepare($donor_query);
    $stmt->bind_param('i', $donor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $donor = $result->fetch_object();
    $stmt->close();
    
    if (!$donor) {
        header('Location: index.php');
        exit;
    }
    
    // Get payment plan templates
    $templates = [];
    $templates_check = $db->query("SHOW TABLES LIKE 'payment_plan_templates'");
    if ($templates_check && $templates_check->num_rows > 0) {
        $templates_query = "SELECT * FROM payment_plan_templates WHERE is_active = 1 ORDER BY duration_months ASC";
        $t_result = $db->query($templates_query);
        if ($t_result) {
            while ($row = $t_result->fetch_assoc()) {
                $templates[] = $row;
            }
        }
    }

    // If no templates, use defaults
    if (empty($templates)) {
        $templates = [
            ['id' => 'def_1', 'name' => 'One-Time Payment', 'duration_months' => 1],
            ['id' => 'def_3', 'name' => 'Quarterly (3 Months)', 'duration_months' => 3],
            ['id' => 'def_6', 'name' => '6 Months Plan', 'duration_months' => 6],
            ['id' => 'def_12', 'name' => '12 Months Plan', 'duration_months' => 12],
        ];
    }
    
} catch (Exception $e) {
    error_log("Conversation Error: " . $e->getMessage());
    header('Location: index.php');
    exit;
}

$page_title = 'Live Call';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $page_title; ?> - Call Center</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <link rel="stylesheet" href="assets/call-center.css">
    <link rel="stylesheet" href="assets/call-widget.css">
    <style>
        .conversation-page {
            max-width: 1100px;
            margin: 0 auto;
            padding-top: 20px;
        }
        
        /* Steps Wizard */
        .step-container {
            display: none;
            animation: fadeIn 0.3s ease-in-out;
        }
        
        .step-container.active {
            display: block;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .step-card {
            background: white;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        .step-header {
            background: #f8fafc;
            padding: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .step-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: #0a6286;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
        }
        
        .step-number {
            background: #0a6286;
            color: white;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            margin-right: 0.75rem;
        }
        
        .step-body {
            padding: 2rem;
        }
        
        /* Verification Styles */
        .verification-item {
            background: #f1f5f9;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: flex-start;
        }
        
        .form-check-input {
            width: 1.5em;
            height: 1.5em;
            margin-top: 0.2em;
            margin-right: 1em;
            cursor: pointer;
        }
        
        .verification-text {
            flex: 1;
        }
        
        .verification-question {
            font-weight: 600;
            color: #334155;
            margin-bottom: 0.25rem;
        }
        
        .verification-detail {
            color: #64748b;
            font-size: 0.9rem;
        }
        
        /* Choice Grid */
        .choice-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        .choice-card {
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .choice-card:hover {
            border-color: #0a6286;
            background: #f0f9ff;
        }
        
        .choice-card.selected {
            border-color: #0a6286;
            background: #e0f2fe;
            position: relative;
        }
        
        .choice-icon {
            font-size: 2rem;
            color: #0a6286;
            margin-bottom: 1rem;
        }
        
        .choice-label {
            font-weight: 700;
            font-size: 1.125rem;
        }
        
        /* Split Layout for Plan Selection */
        @media (min-width: 992px) {
            .step-split-layout {
                display: flex;
                gap: 1.5rem;
                align-items: flex-start;
            }
            .step-split-left {
                flex: 3;
            }
            .step-split-right {
                flex: 2;
                position: sticky;
                top: 100px;
            }
        }
        
        /* Plan Selection Styles */
        .plan-cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .plan-card {
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 1.25rem 1rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
            background: white;
        }
        
        .plan-card:hover {
            border-color: #3b82f6;
            transform: translateY(-2px);
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
        }
        
        .plan-card.selected {
            border-color: #0a6286;
            background: #f0f9ff;
            box-shadow: 0 4px 6px -1px rgba(10, 98, 134, 0.2);
        }
        
        .plan-name {
            font-weight: 700;
            font-size: 0.95rem;
            color: #0a6286;
            margin-bottom: 0.5rem;
        }
        
        .plan-duration {
            font-size: 0.85rem;
            color: #64748b;
            margin-bottom: 0.75rem;
        }
        
        .plan-check {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            border: 2px solid #e2e8f0;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            transition: all 0.2s;
        }
        
        .plan-card.selected .plan-check {
            background: #0a6286;
            border-color: #0a6286;
        }
        
        /* Plan Summary Box */
        .plan-summary-box {
            background: white;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            display: none; /* Hidden until plan selected */
        }
        
        .plan-summary-box.active {
            display: block;
            animation: fadeIn 0.3s;
        }
        
        .summary-header {
            background: #f8fafc;
            padding: 1rem 1.25rem;
            border-bottom: 1px solid #e2e8f0;
            font-weight: 700;
            color: #334155;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .summary-body {
            padding: 1.25rem;
        }
        
        .config-section {
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 1.25rem;
            margin-bottom: 1.25rem;
        }
        
        .preview-section {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1rem;
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }
        
        .summary-row.total {
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px solid #e2e8f0;
            font-weight: 700;
            font-size: 1.1rem;
            color: #0a6286;
        }
        
        .summary-row label {
            color: #64748b;
        }
        
        .summary-row span {
            color: #1e293b;
            font-weight: 600;
        }
        
        @media (max-width: 768px) {
            .choice-grid {
                grid-template-columns: 1fr;
            }
            
            .plan-cards-grid {
                grid-template-columns: 1fr 1fr;
            }
            
            .plan-summary-box {
                margin-top: 1.5rem;
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
            <div class="conversation-page">
                <form id="conversationForm" method="POST" action="process-conversation.php">
                    <input type="hidden" name="session_id" value="<?php echo $session_id; ?>">
                    <input type="hidden" name="donor_id" value="<?php echo $donor_id; ?>">
                    <input type="hidden" name="queue_id" value="<?php echo $queue_id; ?>">
                    <input type="hidden" name="plan_template_id" id="selectedPlanId">
                    <input type="hidden" name="plan_duration" id="selectedDuration">
                    
                    <!-- Step 1: Verification -->
                    <div class="step-container active" id="step1">
                        <div class="step-card">
                            <div class="step-header">
                                <div class="step-title">
                                    <span class="step-number">1</span>
                                    Verify Pledge Details
                                </div>
                                <p class="text-muted mb-0">Ask the donor to confirm their pledge information.</p>
                            </div>
                            <div class="step-body">
                                <div class="verification-item">
                                    <input class="form-check-input" type="checkbox" id="verifyAmount">
                                    <div class="verification-text">
                                        <div class="verification-question">
                                            "Did you pledge £<?php echo number_format((float)$donor->balance, 2); ?>?"
                                        </div>
                                        <div class="verification-detail">
                                            Original Pledge: £<?php echo number_format((float)$donor->pledge_amount, 2); ?>
                                            <?php if ($donor->pledge_date): ?>
                                                on <?php echo date('M j, Y', strtotime($donor->pledge_date)); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <?php if ($donor->church_name || $donor->city): ?>
                                <div class="verification-item">
                                    <input class="form-check-input" type="checkbox" id="verifyLocation">
                                    <div class="verification-text">
                                        <div class="verification-question">
                                            "Is your church <?php echo htmlspecialchars($donor->church_name ?? 'Unknown'); ?> in <?php echo htmlspecialchars($donor->city ?? 'Unknown'); ?>?"
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <div class="mt-4 d-flex justify-content-between">
                                    <button type="button" class="btn btn-outline-secondary" onclick="window.location.href='index.php'">
                                        <i class="fas fa-times me-2"></i>Cancel Call
                                    </button>
                                    <button type="button" class="btn btn-primary btn-lg" id="btnStep1Next" disabled onclick="goToStep(2)">
                                        Next Step <i class="fas fa-arrow-right ms-2"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Step 2: Payment Readiness -->
                    <div class="step-container" id="step2">
                        <div class="step-card">
                            <div class="step-header">
                                <div class="step-title">
                                    <span class="step-number">2</span>
                                    Payment Readiness
                                </div>
                                <p class="text-muted mb-0">Is the donor ready to start paying today?</p>
                            </div>
                            <div class="step-body">
                                <div class="choice-grid">
                                    <div class="choice-card" onclick="selectReadiness('yes')">
                                        <div class="choice-icon"><i class="fas fa-check-circle text-success"></i></div>
                                        <div class="choice-label">Yes, Ready to Pay</div>
                                        <p class="text-muted mt-2 mb-0">Proceed to payment plans</p>
                                    </div>
                                    
                                    <div class="choice-card" onclick="selectReadiness('no')">
                                        <div class="choice-icon"><i class="fas fa-clock text-warning"></i></div>
                                        <div class="choice-label">No, Not Ready</div>
                                        <p class="text-muted mt-2 mb-0">Schedule for later or discuss reasons</p>
                                    </div>
                                </div>
                                <input type="hidden" name="ready_to_pay" id="readyToPayInput">
                                
                                <div class="mt-4 d-flex justify-content-between">
                                    <button type="button" class="btn btn-outline-secondary" onclick="goToStep(1)">
                                        <i class="fas fa-arrow-left me-2"></i>Back
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Step 3: Payment Plan -->
                    <div class="step-container" id="step3">
                        <div class="step-card">
                            <div class="step-header">
                                <div class="step-title">
                                    <span class="step-number">3</span>
                                    Select Payment Plan
                                </div>
                                <p class="text-muted mb-0">How would they like to clear the balance?</p>
                            </div>
                            <div class="step-body">
                                <div class="step-split-layout">
                                    <div class="step-split-left">
                                        <h6 class="mb-3 text-muted small fw-bold text-uppercase">Available Plans</h6>
                                        <div class="plan-cards-grid">
                                            <?php foreach ($templates as $template): ?>
                                            <div class="plan-card" onclick="selectPlan('<?php echo $template['id']; ?>', <?php echo $template['duration_months']; ?>)">
                                                <div class="plan-name"><?php echo htmlspecialchars($template['name']); ?></div>
                                                <div class="plan-duration">
                                                    <?php echo $template['duration_months'] > 1 ? $template['duration_months'] . ' Months' : 'One-time'; ?>
                                                </div>
                                                <div class="plan-check">
                                                    <i class="fas fa-check"></i>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                            
                                            <div class="plan-card" onclick="selectPlan('custom', 0)">
                                                <div class="plan-name">Custom Plan</div>
                                                <div class="plan-duration">Set parameters</div>
                                                <div class="plan-check">
                                                    <i class="fas fa-cog"></i>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Back Button for Desktop (Left Column) -->
                                        <button type="button" class="btn btn-outline-secondary d-none d-lg-inline-block" onclick="goToStep(2)">
                                            <i class="fas fa-arrow-left me-2"></i>Back
                                        </button>
                                    </div>
                                    
                                    <div class="step-split-right">
                                        <!-- Summary & Preview Box -->
                                        <div id="planSummaryBox" class="plan-summary-box">
                                            <div class="summary-header">
                                                <span><i class="fas fa-file-invoice-dollar me-2"></i>Plan Preview</span>
                                            </div>
                                            <div class="summary-body">
                                                <!-- Config Section -->
                                                <div class="config-section">
                                                    <div class="mb-3">
                                                        <label class="form-label small fw-bold text-muted">Start Date</label>
                                                        <input type="date" class="form-control form-control-sm" name="start_date" id="startDate" 
                                                               value="<?php echo date('Y-m-d'); ?>" min="<?php echo date('Y-m-d'); ?>" onchange="calculatePreview()">
                                                    </div>
                                                    
                                                    <!-- Custom Plan Options (Visible only for custom) -->
                                                    <div id="customPlanOptions" style="display: none;">
                                                        <div class="mb-3">
                                                            <label class="form-label small fw-bold text-muted">Frequency</label>
                                                            <select class="form-select form-select-sm" id="customFrequency" onchange="calculatePreview()">
                                                                <option value="weekly">Weekly</option>
                                                                <option value="biweekly">Bi-Weekly</option>
                                                                <option value="monthly" selected>Monthly</option>
                                                                <option value="quarterly">Quarterly</option>
                                                                <option value="annually">Annually</option>
                                                            </select>
                                                        </div>
                                                        
                                                        <div class="mb-3">
                                                            <label class="form-label small fw-bold text-muted">Number of Payments</label>
                                                            <div class="input-group input-group-sm">
                                                                <button type="button" class="btn btn-outline-secondary" onclick="adjustPayments(-1)">-</button>
                                                                <input type="number" class="form-control text-center" id="customPayments" 
                                                                       value="12" min="1" max="120" onchange="calculatePreview()">
                                                                <button type="button" class="btn btn-outline-secondary" onclick="adjustPayments(1)">+</button>
                                                            </div>
                                                        </div>
                                                        
                                                        <div class="mb-3">
                                                            <label class="form-label small fw-bold text-muted">Payment Day</label>
                                                            <input type="number" class="form-control form-control-sm" id="customPaymentDay" 
                                                                   value="1" min="1" max="28" placeholder="Day of Month" onchange="calculatePreview()">
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <!-- Preview Section -->
                                                <div class="preview-section">
                                                    <div class="summary-row">
                                                        <label>Installments</label>
                                                        <span id="previewCount">-</span>
                                                    </div>
                                                    <div class="summary-row">
                                                        <label>First Payment</label>
                                                        <span id="previewFirstDate">-</span>
                                                    </div>
                                                    <div class="summary-row">
                                                        <label>Last Payment</label>
                                                        <span id="previewLastDate">-</span>
                                                    </div>
                                                    <div class="summary-row total">
                                                        <label id="previewAmountLabel">Monthly</label>
                                                        <span class="text-success" id="previewMonthly">-</span>
                                                    </div>
                                                    <div class="summary-row" style="margin-bottom: 0;">
                                                        <label>Total Pledge</label>
                                                        <span>£<?php echo number_format((float)$donor->balance, 2); ?></span>
                                                    </div>
                                                </div>
                                                
                                                <div class="mt-3">
                                                    <button type="button" class="btn btn-success w-100" id="btnFinish" disabled onclick="finishCall()">
                                                        Confirm Plan & Finish <i class="fas fa-check ms-2"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Placeholder when no plan selected -->
                                        <div id="planPlaceholder" class="text-center py-5 text-muted">
                                            <i class="fas fa-arrow-left mb-2" style="font-size: 1.5rem;"></i>
                                            <p>Select a plan on the left to see details</p>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Mobile Back Button -->
                                <div class="mt-4 d-lg-none">
                                    <button type="button" class="btn btn-outline-secondary" onclick="goToStep(2)">
                                        <i class="fas fa-arrow-left me-2"></i>Back
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script src="assets/call-widget.js"></script>
<script>
    // Initialize Call Widget
    document.addEventListener('DOMContentLoaded', function() {
        CallWidget.init({
            sessionId: <?php echo $session_id; ?>,
            donorId: <?php echo $donor_id; ?>,
            donorName: '<?php echo addslashes($donor->name); ?>',
            donorPhone: '<?php echo addslashes($donor->phone); ?>',
            pledgeAmount: <?php echo $donor->pledge_amount; ?>,
            pledgeDate: '<?php echo $donor->pledge_date ? date('M j, Y', strtotime($donor->pledge_date)) : 'Unknown'; ?>',
            registrar: '<?php echo addslashes($donor->registrar_name); ?>',
            church: '<?php echo addslashes($donor->church_name ?? $donor->city ?? 'Unknown'); ?>'
        });
    });
    
    // Step Logic
    function goToStep(stepNum) {
        document.querySelectorAll('.step-container').forEach(el => el.classList.remove('active'));
        document.getElementById('step' + stepNum).classList.add('active');
    }
    
    // Step 1 Validation
    const verifyAmount = document.getElementById('verifyAmount');
    const verifyLocation = document.getElementById('verifyLocation');
    const btnStep1Next = document.getElementById('btnStep1Next');
    
    function checkStep1() {
        const amountChecked = verifyAmount.checked;
        const locationChecked = verifyLocation ? verifyLocation.checked : true;
        
        if (amountChecked && locationChecked) {
            btnStep1Next.disabled = false;
        } else {
            btnStep1Next.disabled = true;
        }
    }
    
    if(verifyAmount) verifyAmount.addEventListener('change', checkStep1);
    if(verifyLocation) verifyLocation.addEventListener('change', checkStep1);
    
    // Step 2 Logic
    function selectReadiness(choice) {
        document.getElementById('readyToPayInput').value = choice;
        if (choice === 'yes') {
            goToStep(3);
        } else {
            window.location.href = 'schedule-callback.php?session_id=<?php echo $session_id; ?>&donor_id=<?php echo $donor_id; ?>&queue_id=<?php echo $queue_id; ?>&status=not_ready_to_pay';
        }
    }
    
    // Step 3 Plan Logic
    const donorBalance = <?php echo (float)$donor->balance; ?>;
    let selectedDuration = 0;
    
    function selectPlan(id, duration) {
        // Update UI
        document.querySelectorAll('.plan-card').forEach(el => el.classList.remove('selected'));
        event.currentTarget.classList.add('selected');
        
        // Update Inputs
        document.getElementById('selectedPlanId').value = id;
        
        const summaryBox = document.getElementById('planSummaryBox');
        const placeholder = document.getElementById('planPlaceholder');
        const customOptions = document.getElementById('customPlanOptions');
        
        placeholder.style.display = 'none';
        summaryBox.classList.add('active');
        
        if (id === 'custom') {
            customOptions.style.display = 'block';
            // Initial calc for custom
            calculatePreview();
        } else {
            customOptions.style.display = 'none';
            selectedDuration = duration;
            // Standard logic for fixed plans
            document.getElementById('selectedDuration').value = selectedDuration;
            calculatePreview(true); // true = use selectedDuration
        }
        
        // On mobile, scroll to summary
        if (window.innerWidth < 992) {
            summaryBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }
    
    function adjustPayments(delta) {
        const input = document.getElementById('customPayments');
        let val = parseInt(input.value) || 12;
        val += delta;
        if (val < 1) val = 1;
        if (val > 120) val = 120;
        input.value = val;
        calculatePreview();
    }
    
    function calculatePreview(isStandard = false) {
        let frequency = 'monthly';
        let count = selectedDuration; // Default for standard
        let amountLabel = 'Monthly';
        
        if (!isStandard && document.getElementById('selectedPlanId').value === 'custom') {
            frequency = document.getElementById('customFrequency').value;
            count = parseInt(document.getElementById('customPayments').value) || 12;
            
            // Update label based on frequency
            switch(frequency) {
                case 'weekly': amountLabel = 'Weekly'; break;
                case 'biweekly': amountLabel = 'Bi-Weekly'; break;
                case 'quarterly': amountLabel = 'Quarterly'; break;
                case 'annually': amountLabel = 'Annually'; break;
                default: amountLabel = 'Monthly';
            }
        } else {
            // Standard plan is always monthly
            amountLabel = 'Monthly';
        }
        
        document.getElementById('previewAmountLabel').textContent = amountLabel;
        document.getElementById('selectedDuration').value = count; // Approx duration or count

        // Calculate Installment Amount
        const installment = donorBalance / count;
        
        // Update UI
        document.getElementById('previewMonthly').textContent = '£' + installment.toLocaleString('en-GB', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        document.getElementById('previewCount').textContent = count;
        
        // Calculate Dates
        const startDateInput = document.getElementById('startDate').value;
        if (startDateInput) {
            const start = new Date(startDateInput);
            let end = new Date(startDateInput);
            
            // Calculate End Date based on Frequency * Count
            if (frequency === 'weekly') {
                end.setDate(end.getDate() + (7 * (count - 1)));
            } else if (frequency === 'biweekly') {
                end.setDate(end.getDate() + (14 * (count - 1)));
            } else if (frequency === 'monthly') {
                end.setMonth(end.getMonth() + (count - 1));
            } else if (frequency === 'quarterly') {
                end.setMonth(end.getMonth() + (3 * (count - 1)));
            } else if (frequency === 'annually') {
                end.setFullYear(end.getFullYear() + (count - 1));
            }
            
            const options = { day: 'numeric', month: 'short', year: 'numeric' };
            document.getElementById('previewFirstDate').textContent = start.toLocaleDateString('en-GB', options);
            document.getElementById('previewLastDate').textContent = end.toLocaleDateString('en-GB', options);
        }
        
        document.getElementById('btnFinish').disabled = false;
    }
    
    function finishCall() {
        alert('Plan Created! Redirecting to queue...');
        window.location.href = 'index.php';
    }
</script>
</body>
</html>
