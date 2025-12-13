<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../shared/auth.php';
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../shared/csrf.php';

require_login();
require_admin();

$page_title = 'Bulk SMS/WhatsApp';
$current_user = current_user();
$db = db();

// Get filter options
$agents = [];
$registrars = [];
$representatives = [];
$cities = [];

try {
    // Agents
    $result = $db->query("SELECT id, name FROM users WHERE role IN ('admin', 'registrar') ORDER BY name");
    if ($result) $agents = $result->fetch_all(MYSQLI_ASSOC);

    // Registrars (distinct from donors table)
    $result = $db->query("SELECT DISTINCT u.id, u.name FROM donors d JOIN users u ON d.registered_by_user_id = u.id ORDER BY u.name");
    if ($result) $registrars = $result->fetch_all(MYSQLI_ASSOC);
    
    // Representatives (distinct from donors table)
    // Note: Assuming representative_id links to users table, or a separate representatives table? 
    // Based on previous file, it seemed linked to users. Let's check schema or assume users for now.
    // Actually, earlier code in plan-success.php used: JOIN users u ON d.representative_id = u.id
    $result = $db->query("SELECT DISTINCT u.id, u.name FROM donors d JOIN users u ON d.representative_id = u.id ORDER BY u.name");
    if ($result) $representatives = $result->fetch_all(MYSQLI_ASSOC);

    // Cities
    $result = $db->query("SELECT DISTINCT city FROM donors WHERE city IS NOT NULL AND city != '' ORDER BY city");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $cities[] = $row['city'];
        }
    }
} catch (Exception $e) {
    // Log error
}

// Get SMS Templates
$templates = [];
try {
    $result = $db->query("SELECT id, name, message_en, message_am, message_ti FROM sms_templates WHERE is_active = 1 ORDER BY name");
    if ($result) $templates = $result->fetch_all(MYSQLI_ASSOC);
} catch (Exception $e) {
    // Log error
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $page_title; ?> - Fundraising Admin</title>
    <link rel="icon" type="image/svg+xml" href="../../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../../assets/admin.css">
    <link rel="stylesheet" href="../assets/donor-management.css">
    <style>
        .filter-card {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 0.5rem;
        }
        .filter-section-title {
            font-size: 0.875rem;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.75rem;
        }
        .donor-count-badge {
            font-size: 1.25rem;
            font-weight: 700;
        }
        .step-indicator {
            display: flex;
            align-items: center;
            margin-bottom: 2rem;
        }
        .step {
            flex: 1;
            text-align: center;
            position: relative;
            z-index: 1;
        }
        .step-circle {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 50%;
            background-color: #e2e8f0;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.5rem;
            font-weight: 600;
            transition: all 0.3s;
        }
        .step.active .step-circle {
            background-color: #0a6286;
            color: white;
            box-shadow: 0 0 0 4px rgba(10, 98, 134, 0.2);
        }
        .step.completed .step-circle {
            background-color: #10b981;
            color: white;
        }
        .step-line {
            position: absolute;
            top: 1.25rem;
            left: 0;
            width: 100%;
            height: 2px;
            background-color: #e2e8f0;
            z-index: -1;
        }
        .step:first-child .step-line {
            left: 50%;
            width: 50%;
        }
        .step:last-child .step-line {
            width: 50%;
        }
        .result-card {
            transition: all 0.3s;
        }
        .result-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="container-fluid p-3 p-md-4">
                
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 mb-1">Bulk Messaging</h1>
                        <p class="text-muted mb-0">Send SMS or WhatsApp messages to multiple donors</p>
                    </div>
                </div>

                <!-- Step Indicator -->
                <div class="step-indicator">
                    <div class="step active" id="step1-indicator">
                        <div class="step-line"></div>
                        <div class="step-circle">1</div>
                        <small>Filter Audience</small>
                    </div>
                    <div class="step" id="step2-indicator">
                        <div class="step-line"></div>
                        <div class="step-circle">2</div>
                        <small>Compose</small>
                    </div>
                    <div class="step" id="step3-indicator">
                        <div class="step-line"></div>
                        <div class="step-circle">3</div>
                        <small>Send & Report</small>
                    </div>
                </div>

                <!-- STEP 1: Filter Audience -->
                <div id="step1" class="step-content">
                    <div class="card shadow-sm border-0 mb-4">
                        <div class="card-header bg-white py-3">
                            <h5 class="mb-0"><i class="fas fa-filter me-2 text-primary"></i>Select Recipients</h5>
                        </div>
                        <div class="card-body">
                            <form id="filterForm">
                                <div class="row g-3">
                                    <!-- User/Agent Filters -->
                                    <div class="col-12 col-md-4">
                                        <div class="filter-card p-3 h-100">
                                            <div class="filter-section-title">Assignments</div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label small fw-bold">Assigned Agent</label>
                                                <select class="form-select" name="agent_id">
                                                    <option value="">Any Agent</option>
                                                    <?php foreach ($agents as $agent): ?>
                                                        <option value="<?php echo $agent['id']; ?>"><?php echo htmlspecialchars($agent['name']); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label small fw-bold">Registrar</label>
                                                <select class="form-select" name="registrar_id">
                                                    <option value="">Any Registrar</option>
                                                    <?php foreach ($registrars as $registrar): ?>
                                                        <option value="<?php echo $registrar['id']; ?>"><?php echo htmlspecialchars($registrar['name']); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <div class="mb-0">
                                                <label class="form-label small fw-bold">Representative</label>
                                                <select class="form-select" name="representative_id">
                                                    <option value="">Any Representative</option>
                                                    <?php foreach ($representatives as $rep): ?>
                                                        <option value="<?php echo $rep['id']; ?>"><?php echo htmlspecialchars($rep['name']); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Financial Filters -->
                                    <div class="col-12 col-md-4">
                                        <div class="filter-card p-3 h-100">
                                            <div class="filter-section-title">Financial & Location</div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label small fw-bold">Payment Method</label>
                                                <select class="form-select" name="payment_method">
                                                    <option value="">Any Method</option>
                                                    <option value="bank_transfer">Bank Transfer</option>
                                                    <option value="cash">Cash</option>
                                                    <option value="card">Card</option>
                                                    <option value="cheque">Cheque</option>
                                                </select>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label small fw-bold">City</label>
                                                <select class="form-select" name="city">
                                                    <option value="">Any City</option>
                                                    <?php foreach ($cities as $city): ?>
                                                        <option value="<?php echo htmlspecialchars($city); ?>"><?php echo htmlspecialchars($city); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <div class="row g-2">
                                                <div class="col-6">
                                                    <label class="form-label small fw-bold">Min Amount</label>
                                                    <input type="number" class="form-control" name="min_amount" placeholder="0.00" step="0.01">
                                                </div>
                                                <div class="col-6">
                                                    <label class="form-label small fw-bold">Max Amount</label>
                                                    <input type="number" class="form-control" name="max_amount" placeholder="Max" step="0.01">
                                                </div>
                                                <div class="col-12">
                                                    <div class="form-text small">Applies to Active Plan Monthly Amount</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Other Filters -->
                                    <div class="col-12 col-md-4">
                                        <div class="filter-card p-3 h-100">
                                            <div class="filter-section-title">Status & Language</div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label small fw-bold">Payment Status</label>
                                                <select class="form-select" name="payment_status">
                                                    <option value="">Any Status</option>
                                                    <option value="active">Active Plan</option>
                                                    <option value="completed">Completed</option>
                                                    <option value="overdue">Overdue</option>
                                                    <option value="not_started">Not Started</option>
                                                </select>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label small fw-bold">Preferred Language</label>
                                                <select class="form-select" name="language">
                                                    <option value="">Any Language</option>
                                                    <option value="en">English</option>
                                                    <option value="am">Amharic</option>
                                                    <option value="ti">Tigrinya</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-4 d-flex justify-content-between align-items-center bg-light p-3 rounded">
                                    <div class="d-flex align-items-center">
                                        <div class="me-3">
                                            <span class="text-muted small text-uppercase fw-bold d-block">Matching Donors</span>
                                            <span class="donor-count-badge text-primary" id="donorCount">0</span>
                                        </div>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" id="btnRefreshCount">
                                            <i class="fas fa-sync-alt"></i> Refresh Count
                                        </button>
                                    </div>
                                    <button type="button" class="btn btn-primary" id="btnNextStep" disabled>
                                        Next: Compose Message <i class="fas fa-arrow-right ms-2"></i>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- STEP 2: Compose Message -->
                <div id="step2" class="step-content d-none">
                    <div class="card shadow-sm border-0 mb-4">
                        <div class="card-header bg-white py-3">
                            <h5 class="mb-0"><i class="fas fa-pen-fancy me-2 text-primary"></i>Compose Message</h5>
                        </div>
                        <div class="card-body">
                            <form id="messageForm">
                                <div class="row">
                                    <div class="col-12 col-lg-8">
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Channel</label>
                                            <div class="d-flex gap-3">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="radio" name="channel" id="channelWhatsapp" value="whatsapp" checked>
                                                    <label class="form-check-label" for="channelWhatsapp">
                                                        <i class="fab fa-whatsapp text-success me-1"></i> WhatsApp (with SMS Fallback)
                                                    </label>
                                                </div>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="radio" name="channel" id="channelSms" value="sms">
                                                    <label class="form-check-label" for="channelSms">
                                                        <i class="fas fa-sms text-info me-1"></i> SMS Only
                                                    </label>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Template</label>
                                            <select class="form-select" id="templateSelect">
                                                <option value="">-- Select a Template (Optional) --</option>
                                                <?php foreach ($templates as $t): ?>
                                                    <option value="<?php echo htmlspecialchars($t['id']); ?>" 
                                                            data-en="<?php echo htmlspecialchars($t['message_en']); ?>"
                                                            data-am="<?php echo htmlspecialchars($t['message_am']); ?>"
                                                            data-ti="<?php echo htmlspecialchars($t['message_ti']); ?>">
                                                        <?php echo htmlspecialchars($t['name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Message Content</label>
                                            <textarea class="form-control" name="message" id="messageContent" rows="5" required placeholder="Type your message here... Variables like {name} will be replaced."></textarea>
                                            <div class="d-flex justify-content-between mt-1">
                                                <small class="text-muted">
                                                    Available variables: {name}, {amount}, {frequency}, {start_date}, {next_payment_due}, {payment_method}, {portal_link}
                                                </small>
                                                <span class="badge bg-light text-dark border" id="charCount">0 chars</span>
                                            </div>
                                        </div>

                                        <div class="alert alert-info small">
                                            <i class="fas fa-info-circle me-1"></i>
                                            <strong>Note:</strong> Messages will be automatically translated based on the donor's preferred language if using a template. Custom messages will be sent as-is.
                                        </div>
                                    </div>

                                    <div class="col-12 col-lg-4">
                                        <div class="card bg-light border-0">
                                            <div class="card-body">
                                                <h6 class="card-title fw-bold">Summary</h6>
                                                <ul class="list-group list-group-flush bg-transparent">
                                                    <li class="list-group-item bg-transparent d-flex justify-content-between px-0">
                                                        <span>Recipients:</span>
                                                        <span class="fw-bold" id="summaryCount">0</span>
                                                    </li>
                                                    <li class="list-group-item bg-transparent d-flex justify-content-between px-0">
                                                        <span>Channel:</span>
                                                        <span class="fw-bold" id="summaryChannel">WhatsApp</span>
                                                    </li>
                                                    <li class="list-group-item bg-transparent d-flex justify-content-between px-0">
                                                        <span>Est. Cost:</span>
                                                        <span class="fw-bold" id="summaryCost">Calculating...</span>
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-4 d-flex justify-content-between">
                                    <button type="button" class="btn btn-outline-secondary" id="btnBackToFilter">
                                        <i class="fas fa-arrow-left me-2"></i> Back
                                    </button>
                                    <button type="button" class="btn btn-success btn-lg" id="btnSend">
                                        <i class="fas fa-paper-plane me-2"></i> Send Bulk Message
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- STEP 3: Report -->
                <div id="step3" class="step-content d-none">
                    <div class="card shadow-sm border-0 mb-4">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="fas fa-chart-pie me-2 text-primary"></i>Sending Report</h5>
                            <button class="btn btn-outline-primary btn-sm" onclick="location.reload()">New Message</button>
                        </div>
                        <div class="card-body">
                            <!-- Progress Bar -->
                            <div class="mb-4" id="progressSection">
                                <h6 class="mb-2">Sending Progress</h6>
                                <div class="progress" style="height: 25px;">
                                    <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%">0%</div>
                                </div>
                                <div class="text-center mt-2 small text-muted" id="progressText">Starting...</div>
                            </div>

                            <!-- Summary Stats -->
                            <div class="row g-4 mb-4 text-center">
                                <div class="col-6 col-md-3">
                                    <div class="result-card p-3 rounded bg-light border h-100">
                                        <div class="text-primary h2 mb-0" id="statTotal">0</div>
                                        <div class="text-muted small uppercase">Total</div>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="result-card p-3 rounded bg-light border h-100">
                                        <div class="text-success h2 mb-0" id="statSuccess">0</div>
                                        <div class="text-muted small uppercase">Sent</div>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="result-card p-3 rounded bg-light border h-100">
                                        <div class="text-danger h2 mb-0" id="statFailed">0</div>
                                        <div class="text-muted small uppercase">Failed</div>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="result-card p-3 rounded bg-light border h-100">
                                        <div class="text-warning h2 mb-0" id="statFallback">0</div>
                                        <div class="text-muted small uppercase">SMS Fallback</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Failures Table -->
                            <h6 class="mb-3 text-danger">Failed Messages (Action Required)</h6>
                            <div class="table-responsive">
                                <table class="table table-hover border">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Name</th>
                                            <th>Phone</th>
                                            <th>Error</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="failuresTableBody">
                                        <!-- Populated via JS -->
                                    </tbody>
                                </table>
                                <div id="noFailuresMsg" class="text-center py-4 text-muted d-none">
                                    <i class="fas fa-check-circle text-success fa-2x mb-2"></i>
                                    <p class="mb-0">No failures! All messages sent successfully.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/admin.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // State
    let totalDonors = 0;
    let selectedDonors = [];
    let isSending = false;
    let batchSize = 10; // Process 10 at a time

    // Elements
    const step1 = document.getElementById('step1');
    const step2 = document.getElementById('step2');
    const step3 = document.getElementById('step3');
    const step1Ind = document.getElementById('step1-indicator');
    const step2Ind = document.getElementById('step2-indicator');
    const step3Ind = document.getElementById('step3-indicator');

    const filterForm = document.getElementById('filterForm');
    const btnRefreshCount = document.getElementById('btnRefreshCount');
    const donorCount = document.getElementById('donorCount');
    const btnNextStep = document.getElementById('btnNextStep');
    const btnBackToFilter = document.getElementById('btnBackToFilter');
    const btnSend = document.getElementById('btnSend');
    
    const messageContent = document.getElementById('messageContent');
    const charCount = document.getElementById('charCount');
    const templateSelect = document.getElementById('templateSelect');

    // Initial Count
    fetchDonorCount();

    // Event Listeners
    btnRefreshCount.addEventListener('click', fetchDonorCount);
    
    // Auto-refresh count when filters change
    filterForm.querySelectorAll('select, input').forEach(input => {
        input.addEventListener('change', fetchDonorCount);
    });

    // Navigation
    btnNextStep.addEventListener('click', () => {
        if (totalDonors === 0) {
            alert('Please select filters that return at least one donor.');
            return;
        }
        showStep(2);
        document.getElementById('summaryCount').textContent = totalDonors;
        updateSummary();
    });

    btnBackToFilter.addEventListener('click', () => showStep(1));

    btnSend.addEventListener('click', startSendingProcess);

    // Template Selection
    templateSelect.addEventListener('change', function() {
        const selected = this.options[this.selectedIndex];
        if (selected.value) {
            messageContent.value = selected.dataset.en; // Default to English preview
            updateCharCount();
        }
    });

    messageContent.addEventListener('input', updateCharCount);
    document.querySelectorAll('input[name="channel"]').forEach(r => {
        r.addEventListener('change', updateSummary);
    });

    // --- Functions ---

    function showStep(step) {
        step1.classList.add('d-none');
        step2.classList.add('d-none');
        step3.classList.add('d-none');
        
        step1Ind.classList.remove('active', 'completed');
        step2Ind.classList.remove('active', 'completed');
        step3Ind.classList.remove('active', 'completed');

        if (step === 1) {
            step1.classList.remove('d-none');
            step1Ind.classList.add('active');
        } else if (step === 2) {
            step2.classList.remove('d-none');
            step1Ind.classList.add('completed');
            step2Ind.classList.add('active');
        } else if (step === 3) {
            step3.classList.remove('d-none');
            step1Ind.classList.add('completed');
            step2Ind.classList.add('completed');
            step3Ind.classList.add('active');
        }
    }

    async function fetchDonorCount() {
        const formData = new FormData(filterForm);
        const params = new URLSearchParams(formData);
        
        donorCount.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        btnNextStep.disabled = true;

        try {
            const response = await fetch('bulk-send-process.php?action=count&' + params.toString());
            const data = await response.json();
            
            if (data.success) {
                totalDonors = data.count;
                donorCount.textContent = totalDonors;
                btnNextStep.disabled = totalDonors === 0;
            } else {
                donorCount.textContent = 'Error';
                alert('Error fetching count: ' + data.message);
            }
        } catch (e) {
            console.error(e);
            donorCount.textContent = 'Error';
        }
    }

    function updateCharCount() {
        const len = messageContent.value.length;
        charCount.textContent = len + ' chars';
    }

    function updateSummary() {
        const channel = document.querySelector('input[name="channel"]:checked').value;
        document.getElementById('summaryChannel').textContent = channel === 'whatsapp' ? 'WhatsApp' : 'SMS Only';
        
        // Very rough estimate
        const costPerMsg = channel === 'whatsapp' ? 0.05 : 0.04; 
        document.getElementById('summaryCost').textContent = '£' + (totalDonors * costPerMsg).toFixed(2);
    }

    async function startSendingProcess() {
        if (!confirm(`Are you sure you want to send this message to ${totalDonors} donors?`)) return;

        showStep(3);
        isSending = true;
        
        // Reset stats
        document.getElementById('statTotal').textContent = totalDonors;
        let sent = 0, failed = 0, fallback = 0;
        
        const formData = new FormData(filterForm);
        const messageData = new FormData(document.getElementById('messageForm'));
        
        // Merge forms
        for (let [key, value] of messageData.entries()) {
            formData.append(key, value);
        }

        // 1. Get List of Donor IDs
        formData.append('action', 'get_ids');
        
        try {
            const response = await fetch('bulk-send-process.php', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            
            if (!data.success) throw new Error(data.message);
            
            const donorIds = data.ids;
            const total = donorIds.length;
            
            // 2. Process in Batches
            const progressBar = document.getElementById('progressBar');
            const progressText = document.getElementById('progressText');
            const failuresBody = document.getElementById('failuresTableBody');
            
            for (let i = 0; i < total; i += batchSize) {
                const batch = donorIds.slice(i, i + batchSize);
                const currentBatchNum = Math.floor(i / batchSize) + 1;
                const totalBatches = Math.ceil(total / batchSize);
                
                progressText.textContent = `Processing batch ${currentBatchNum} of ${totalBatches}...`;
                
                const batchFormData = new FormData();
                batchFormData.append('action', 'send_batch');
                batchFormData.append('ids', JSON.stringify(batch));
                batchFormData.append('message', messageContent.value);
                batchFormData.append('channel', document.querySelector('input[name="channel"]:checked').value);
                batchFormData.append('template_id', templateSelect.value);
                
                const batchResponse = await fetch('bulk-send-process.php', {
                    method: 'POST',
                    body: batchFormData
                });
                const batchResult = await batchResponse.json();
                
                if (batchResult.success) {
                    batchResult.results.forEach(res => {
                        if (res.status === 'sent') sent++;
                        else failed++;
                        
                        if (res.fallback) fallback++;
                        
                        if (res.status !== 'sent') {
                            // Add to failure table
                            const row = document.createElement('tr');
                            row.innerHTML = `
                                <td>${res.name}</td>
                                <td>${res.phone}</td>
                                <td class="text-danger small">${res.error || 'Unknown'}</td>
                                <td>
                                    <a href="../call-center/make-call.php?donor_id=${res.donor_id}" target="_blank" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-phone"></i>
                                    </a>
                                </td>
                            `;
                            failuresBody.appendChild(row);
                        }
                    });
                }
                
                // Update stats
                document.getElementById('statSuccess').textContent = sent;
                document.getElementById('statFailed').textContent = failed;
                document.getElementById('statFallback').textContent = fallback;
                
                // Update Progress
                const percent = Math.round(((i + batch.length) / total) * 100);
                progressBar.style.width = percent + '%';
                progressBar.textContent = percent + '%';
                progressBar.setAttribute('aria-valuenow', percent);
            }
            
            progressText.textContent = 'Completed!';
            progressBar.classList.remove('progress-bar-animated');
            progressBar.classList.add('bg-success');
            
            if (failed === 0) {
                document.getElementById('noFailuresMsg').classList.remove('d-none');
            }
            
        } catch (e) {
            alert('Error during sending: ' + e.message);
            console.error(e);
        }
    }
});
</script>
</body>
</html>
