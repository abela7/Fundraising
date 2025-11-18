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
            max-width: 900px;
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
        
        /* Plan Selection Styles */
        .plan-cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .plan-card {
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 1.5rem 1rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
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
            font-size: 1rem;
            color: #0a6286;
            margin-bottom: 0.5rem;
        }
        
        .plan-duration {
            font-size: 0.875rem;
            color: #64748b;
            margin-bottom: 1rem;
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
        
        .config-panel {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            display: none;
        }
        
        .config-panel.active {
            display: block;
            animation: fadeIn 0.3s;
        }
        
        .preview-panel {
            border-top: 1px solid #e2e8f0;
            padding-top: 1.5rem;
            display: none;
        }
        
        .preview-panel.active {
            display: block;
            animation: fadeIn 0.3s;
        }
        
        .summary-stat {
            text-align: center;
            padding: 1rem;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        
        .summary-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            color: #64748b;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .summary-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: #0a6286;
        }
        
        @media (max-width: 768px) {
            .choice-grid {
                grid-template-columns: 1fr;
            }
            
            .plan-cards-grid {
                grid-template-columns: 1fr 1fr;
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
                                <input type="hidden" name="plan_template_id" id="selectedPlanId">
                                <input type="hidden" name="plan_duration" id="selectedDuration">
                                
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
                                        <div class="plan-duration">Set duration</div>
                                        <div class="plan-check">
                                            <i class="fas fa-cog"></i>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Configuration Panel -->
                                <div id="configPanel" class="config-panel">
                                    <h6 class="mb-3 fw-bold text-primary"><i class="fas fa-cog me-2"></i>Plan Settings</h6>
                                    
                                    <div class="row g-3">
                                        <!-- Start Date -->
                                        <div class="col-md-6">
                                            <label class="form-label small fw-bold text-muted">Start Date</label>
                                            <input type="date" class="form-control" name="start_date" id="startDate" 
                                                   value="<?php echo date('Y-m-d'); ?>" min="<?php echo date('Y-m-d'); ?>" onchange="calculatePreview()">
                                        </div>
                                        
                                        <!-- Custom Duration (Only visible for Custom) -->
                                        <div class="col-md-6" id="customDurationContainer" style="display: none;">
                                            <label class="form-label small fw-bold text-muted">Duration (Months)</label>
                                            <div class="input-group">
                                                <button type="button" class="btn btn-outline-secondary" onclick="adjustDuration(-1)">-</button>
                                                <input type="number" class="form-control text-center" name="custom_duration" id="customDuration" 
                                                       value="12" min="1" max="60" onchange="calculatePreview()">
                                                <button type="button" class="btn btn-outline-secondary" onclick="adjustDuration(1)">+</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Preview Panel -->
                                <div id="previewPanel" class="preview-panel">
                                    <h6 class="mb-3 fw-bold text-success"><i class="fas fa-calculator me-2"></i>Payment Schedule Preview</h6>
                                    
                                    <div class="row g-3 mb-3">
                                        <div class="col-4">
                                            <div class="summary-stat">
                                                <div class="summary-label">Total</div>
                                                <div class="summary-value">£<?php echo number_format((float)$donor->balance, 2); ?></div>
                                            </div>
                                        </div>
                                        <div class="col-4">
                                            <div class="summary-stat">
                                                <div class="summary-label">Monthly</div>
                                                <div class="summary-value" id="previewMonthly">-</div>
                                            </div>
                                        </div>
                                        <div class="col-4">
                                            <div class="summary-stat">
                                                <div class="summary-label">Installments</div>
                                                <div class="summary-value" id="previewCount">-</div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="alert alert-light border">
                                        <div class="d-flex justify-content-between small">
                                            <span>First Payment: <strong id="previewFirstDate">-</strong></span>
                                            <span>Last Payment: <strong id="previewLastDate">-</strong></span>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mt-4 d-flex justify-content-between">
                                    <button type="button" class="btn btn-outline-secondary" onclick="goToStep(2)">
                                        <i class="fas fa-arrow-left me-2"></i>Back
                                    </button>
                                    <button type="button" class="btn btn-success btn-lg" id="btnFinish" disabled onclick="finishCall()">
                                        Confirm Plan & Finish <i class="fas fa-check ms-2"></i>
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
        
        const configPanel = document.getElementById('configPanel');
        const customContainer = document.getElementById('customDurationContainer');
        
        configPanel.classList.add('active');
        
        if (id === 'custom') {
            customContainer.style.display = 'block';
            selectedDuration = parseInt(document.getElementById('customDuration').value) || 12;
        } else {
            customContainer.style.display = 'none';
            selectedDuration = duration;
        }
        
        document.getElementById('selectedDuration').value = selectedDuration;
        calculatePreview();
    }
    
    function adjustDuration(delta) {
        const input = document.getElementById('customDuration');
        let val = parseInt(input.value) || 12;
        val += delta;
        if (val < 1) val = 1;
        if (val > 60) val = 60;
        input.value = val;
        
        // Only update if Custom is selected
        if (document.getElementById('selectedPlanId').value === 'custom') {
            selectedDuration = val;
            document.getElementById('selectedDuration').value = val;
            calculatePreview();
        }
    }
    
    function calculatePreview() {
        if (!selectedDuration) return;
        
        // Update Duration if Custom
        if (document.getElementById('selectedPlanId').value === 'custom') {
            selectedDuration = parseInt(document.getElementById('customDuration').value);
            document.getElementById('selectedDuration').value = selectedDuration;
        }

        // Calculate Monthly
        const monthly = donorBalance / selectedDuration;
        
        // Update UI
        document.getElementById('previewPanel').classList.add('active');
        document.getElementById('previewMonthly').textContent = '£' + monthly.toLocaleString('en-GB', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        document.getElementById('previewCount').textContent = selectedDuration;
        
        // Calculate Dates
        const startDateInput = document.getElementById('startDate').value;
        if (startDateInput) {
            const start = new Date(startDateInput);
            const end = new Date(startDateInput);
            end.setMonth(end.getMonth() + selectedDuration - 1);
            
            const options = { day: 'numeric', month: 'short', year: 'numeric' };
            document.getElementById('previewFirstDate').textContent = start.toLocaleDateString('en-GB', options);
            document.getElementById('previewLastDate').textContent = end.toLocaleDateString('en-GB', options);
        }
        
        document.getElementById('btnFinish').disabled = false;
    }
    
    function finishCall() {
        // In a real scenario, this would submit the form
        // document.getElementById('conversationForm').submit();
        alert('Plan Created! Redirecting to queue...');
        window.location.href = 'index.php';
    }
</script>
</body>
</html>
