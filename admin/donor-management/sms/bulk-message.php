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

    // Registrars
    $result = $db->query("SELECT DISTINCT u.id, u.name FROM donors d JOIN users u ON d.registered_by_user_id = u.id ORDER BY u.name");
    if ($result) $registrars = $result->fetch_all(MYSQLI_ASSOC);
    
    // Representatives
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
            background-color: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            transition: all 0.2s;
        }
        .filter-card:hover {
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        .filter-section-title {
            font-size: 0.8rem;
            font-weight: 700;
            color: #0a6286;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 1rem;
            border-bottom: 2px solid #e0f2fe;
            padding-bottom: 0.5rem;
        }
        .donor-count-badge {
            font-size: 1.5rem;
            font-weight: 800;
            color: #0a6286;
        }
        .step-indicator {
            display: flex;
            justify-content: center;
            margin-bottom: 2rem;
            padding: 0 1rem;
        }
        .step {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            z-index: 1;
            width: 80px;
        }
        .step-circle {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 50%;
            background-color: #f1f5f9;
            color: #94a3b8;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 0.5rem;
            font-weight: 600;
            transition: all 0.3s;
            border: 2px solid #e2e8f0;
        }
        .step.active .step-circle {
            background-color: #0a6286;
            color: white;
            border-color: #0a6286;
            box-shadow: 0 0 0 4px rgba(10, 98, 134, 0.15);
        }
        .step.completed .step-circle {
            background-color: #10b981;
            color: white;
            border-color: #10b981;
        }
        .step-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: #64748b;
        }
        .step.active .step-label { color: #0a6286; }
        .step.completed .step-label { color: #10b981; }
        
        .step-line {
            flex-grow: 1;
            height: 2px;
            background-color: #e2e8f0;
            margin-top: 1.25rem;
            max-width: 100px;
        }
        .step-line.active { background-color: #10b981; }

        .result-card {
            transition: all 0.3s;
        }
        .result-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        
        /* Form refinements */
        .form-select, .form-control {
            border-radius: 0.5rem;
            padding: 0.6rem 0.8rem;
            font-size: 0.9rem;
            border-color: #e2e8f0;
        }
        .form-select:focus, .form-control:focus {
            border-color: #0a6286;
            box-shadow: 0 0 0 3px rgba(10, 98, 134, 0.1);
        }
        .form-label {
            color: #475569;
            font-size: 0.85rem;
        }
        
        /* Donor List Styles */
        .donor-list-item {
            background: #fff;
            border-bottom: 1px solid #f1f5f9;
            padding: 0.75rem 1rem;
            transition: background 0.2s;
        }
        .donor-list-item:last-child { border-bottom: none; }
        .donor-list-item:hover { background: #f8fafc; }
        .accordion-button:not(.collapsed) {
            background-color: #f0f9ff;
            color: #0a6286;
        }
        .accordion-button:focus {
            box-shadow: none;
            border-color: rgba(10, 98, 134, 0.1);
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
                        <h1 class="h3 mb-1 fw-bold text-primary">Bulk Messaging</h1>
                        <p class="text-muted mb-0">Reach multiple donors efficiently</p>
                    </div>
                </div>

                <!-- Step Indicator -->
                <div class="step-indicator">
                    <div class="step active" id="step1-indicator">
                        <div class="step-circle">1</div>
                        <span class="step-label">Filter</span>
                    </div>
                    <div class="step-line" id="line1"></div>
                    <div class="step" id="step2-indicator">
                        <div class="step-circle">2</div>
                        <span class="step-label">Compose</span>
                    </div>
                    <div class="step-line" id="line2"></div>
                    <div class="step" id="step3-indicator">
                        <div class="step-circle">3</div>
                        <span class="step-label">Send</span>
                    </div>
                </div>

                <!-- STEP 1: Filter Audience -->
                <div id="step1" class="step-content">
                    <div class="card shadow-sm border-0 mb-4">
                        <div class="card-header bg-white py-3 border-bottom">
                            <h5 class="mb-0 text-primary fw-bold"><i class="fas fa-filter me-2"></i>Select Recipients</h5>
                        </div>
                        <div class="card-body bg-light">
                            <form id="filterForm">
                                <div class="row g-3">
                                    <!-- User/Agent Filters -->
                                    <div class="col-12 col-md-4">
                                        <div class="filter-card p-3 h-100">
                                            <div class="filter-section-title"><i class="fas fa-user-tag me-2"></i>Assignments</div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label fw-bold">Assigned Agent</label>
                                                <select class="form-select" name="agent_id">
                                                    <option value="">Any Agent</option>
                                                    <?php foreach ($agents as $agent): ?>
                                                        <option value="<?php echo $agent['id']; ?>"><?php echo htmlspecialchars($agent['name']); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label fw-bold">Registrar</label>
                                                <select class="form-select" name="registrar_id">
                                                    <option value="">Any Registrar</option>
                                                    <?php foreach ($registrars as $registrar): ?>
                                                        <option value="<?php echo $registrar['id']; ?>"><?php echo htmlspecialchars($registrar['name']); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <div class="mb-0">
                                                <label class="form-label fw-bold">Representative</label>
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
                                            <div class="filter-section-title"><i class="fas fa-pound-sign me-2"></i>Financial & Loc.</div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label fw-bold">Payment Method</label>
                                                <select class="form-select" name="payment_method">
                                                    <option value="">Any Method</option>
                                                    <option value="bank_transfer">Bank Transfer</option>
                                                    <option value="cash">Cash</option>
                                                    <option value="card">Card</option>
                                                    <option value="cheque">Cheque</option>
                                                </select>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label fw-bold">City</label>
                                                <select class="form-select" name="city">
                                                    <option value="">Any City</option>
                                                    <?php foreach ($cities as $city): ?>
                                                        <option value="<?php echo htmlspecialchars($city); ?>"><?php echo htmlspecialchars($city); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <div class="row g-2">
                                                <div class="col-6">
                                                    <label class="form-label fw-bold">Min Amount</label>
                                                    <input type="number" class="form-control" name="min_amount" placeholder="0.00" step="0.01">
                                                </div>
                                                <div class="col-6">
                                                    <label class="form-label fw-bold">Max Amount</label>
                                                    <input type="number" class="form-control" name="max_amount" placeholder="Max" step="0.01">
                                                </div>
                                                <div class="col-12">
                                                    <div class="form-text small text-muted"><i class="fas fa-info-circle me-1"></i>Active Plan Monthly Amount</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Other Filters -->
                                    <div class="col-12 col-md-4">
                                        <div class="filter-card p-3 h-100">
                                            <div class="filter-section-title"><i class="fas fa-info-circle me-2"></i>Status & Lang</div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label fw-bold">Payment Status</label>
                                                <select class="form-select" name="payment_status">
                                                    <option value="">Any Status</option>
                                                    <option value="active">Active Plan</option>
                                                    <option value="completed">Completed</option>
                                                    <option value="overdue">Overdue</option>
                                                    <option value="not_started">Not Started</option>
                                                </select>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label fw-bold">Preferred Language</label>
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

                                <div class="mt-4 bg-white p-3 rounded border shadow-sm">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <div class="d-flex align-items-center">
                                            <div class="me-3 text-center text-md-start">
                                                <span class="text-uppercase fw-bold text-muted small d-block">Total Recipients</span>
                                                <span class="donor-count-badge" id="donorCount">0</span>
                                            </div>
                                            <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill" id="btnRefreshCount">
                                                <i class="fas fa-sync-alt"></i> Refresh
                                            </button>
                                        </div>
                                        <button type="button" class="btn btn-primary px-4 rounded-pill fw-bold" id="btnNextStep" disabled>
                                            Next <i class="fas fa-arrow-right ms-2"></i>
                                        </button>
                                    </div>

                                    <!-- Matching Donors Accordion -->
                                    <div class="accordion" id="donorsAccordion">
                                        <div class="accordion-item border rounded overflow-hidden">
                                            <h2 class="accordion-header" id="headingDonors">
                                                <button class="accordion-button collapsed fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#collapseDonors" aria-expanded="false" aria-controls="collapseDonors">
                                                    <i class="fas fa-list me-2 text-secondary"></i> View Matching Donors List
                                                </button>
                                            </h2>
                                            <div id="collapseDonors" class="accordion-collapse collapse" aria-labelledby="headingDonors" data-bs-parent="#donorsAccordion">
                                                <div class="accordion-body p-0">
                                                    <div id="donorsListContainer" class="list-group list-group-flush">
                                                        <!-- Donors loaded here -->
                                                    </div>
                                                    <div class="p-2 text-center border-top bg-light" id="loadMoreContainer" style="display: none;">
                                                        <button type="button" class="btn btn-sm btn-link text-decoration-none fw-bold" id="btnLoadMore">
                                                            Load More Donors <i class="fas fa-chevron-down ms-1"></i>
                                                        </button>
                                                    </div>
                                                    <div id="donorsEmptyState" class="p-4 text-center text-muted" style="display: none;">
                                                        No donors found matching criteria.
                                                    </div>
                                                    <div id="donorsLoading" class="p-4 text-center text-primary" style="display: none;">
                                                        <i class="fas fa-spinner fa-spin fa-lg"></i>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- STEP 2: Compose Message -->
                <div id="step2" class="step-content d-none">
                    <div class="card shadow-sm border-0 mb-4">
                        <div class="card-header bg-white py-3 border-bottom">
                            <h5 class="mb-0 text-primary fw-bold"><i class="fas fa-pen-fancy me-2"></i>Compose Message</h5>
                        </div>
                        <div class="card-body">
                            <form id="messageForm">
                                <div class="row">
                                    <div class="col-12 col-lg-8">
                                        <div class="mb-4">
                                            <label class="form-label fw-bold">Delivery Channel</label>
                                            <div class="d-flex gap-3 flex-wrap">
                                                <div class="form-check card p-3 border shadow-sm" style="flex: 1; min-width: 200px; cursor: pointer;">
                                                    <input class="form-check-input" type="radio" name="channel" id="channelWhatsapp" value="whatsapp" checked>
                                                    <label class="form-check-label w-100 fw-bold" for="channelWhatsapp" style="cursor: pointer;">
                                                        <i class="fab fa-whatsapp text-success me-1 fa-lg"></i> WhatsApp
                                                        <div class="small text-muted mt-1 fw-normal">Priority WhatsApp, fallback to SMS if failed.</div>
                                                    </label>
                                                </div>
                                                <div class="form-check card p-3 border shadow-sm" style="flex: 1; min-width: 200px; cursor: pointer;">
                                                    <input class="form-check-input" type="radio" name="channel" id="channelSms" value="sms">
                                                    <label class="form-check-label w-100 fw-bold" for="channelSms" style="cursor: pointer;">
                                                        <i class="fas fa-sms text-info me-1 fa-lg"></i> SMS Only
                                                        <div class="small text-muted mt-1 fw-normal">Direct SMS delivery only.</div>
                                                    </label>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Use Template</label>
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
                                            <textarea class="form-control" name="message" id="messageContent" rows="6" required placeholder="Type your message here..."></textarea>
                                            <div class="d-flex justify-content-between mt-2 align-items-start">
                                                <small class="text-muted d-block" style="max-width: 70%;">
                                                    <strong>Variables:</strong> {name}, {amount}, {frequency}, {frequency_am}, {start_date}, {next_payment_due}, {payment_method}, {portal_link}
                                                </small>
                                                <span class="badge bg-light text-dark border" id="charCount">0 chars</span>
                                            </div>
                                        </div>

                                        <div class="alert alert-info small border-0 bg-info-light text-dark">
                                            <i class="fas fa-info-circle me-2"></i>
                                            <strong>Pro Tip:</strong> Using a template enables automatic language translation based on donor preference. Custom messages are sent exactly as typed.
                                        </div>
                                    </div>

                                    <div class="col-12 col-lg-4">
                                        <div class="card bg-gray-light border-0 h-100">
                                            <div class="card-body">
                                                <h6 class="card-title fw-bold text-primary mb-3">Send Summary</h6>
                                                <ul class="list-group list-group-flush bg-transparent">
                                                    <li class="list-group-item bg-transparent d-flex justify-content-between px-0 py-2 border-bottom">
                                                        <span class="text-muted">Recipients</span>
                                                        <span class="fw-bold" id="summaryCount">0</span>
                                                    </li>
                                                    <li class="list-group-item bg-transparent d-flex justify-content-between px-0 py-2 border-bottom">
                                                        <span class="text-muted">Primary Channel</span>
                                                        <span class="fw-bold text-success" id="summaryChannel">WhatsApp</span>
                                                    </li>
                                                    <li class="list-group-item bg-transparent d-flex justify-content-between px-0 py-2">
                                                        <span class="text-muted">Est. Cost</span>
                                                        <span class="fw-bold text-primary" id="summaryCost">Calculating...</span>
                                                    </li>
                                                </ul>
                                                <div class="mt-3 small text-muted fst-italic">
                                                    * Cost is an estimate based on standard rates. Actual cost may vary.
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-4 d-flex justify-content-between pt-3 border-top">
                                    <button type="button" class="btn btn-outline-secondary px-4 rounded-pill" id="btnBackToFilter">
                                        <i class="fas fa-arrow-left me-2"></i> Back
                                    </button>
                                    <button type="button" class="btn btn-success btn-lg px-5 rounded-pill shadow fw-bold" id="btnSend">
                                        <i class="fas fa-paper-plane me-2"></i> Send Message
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
                            <h5 class="mb-0 text-primary fw-bold"><i class="fas fa-chart-pie me-2"></i>Sending Report</h5>
                            <button class="btn btn-outline-primary btn-sm rounded-pill" onclick="location.reload()">
                                <i class="fas fa-plus me-1"></i> New Campaign
                            </button>
                        </div>
                        <div class="card-body">
                            <!-- Progress Bar -->
                            <div class="mb-5" id="progressSection">
                                <div class="d-flex justify-content-between mb-2">
                                    <h6 class="fw-bold text-muted">Sending Progress</h6>
                                    <span class="fw-bold text-primary" id="progressPercent">0%</span>
                                </div>
                                <div class="progress" style="height: 12px; border-radius: 6px;">
                                    <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-primary" role="progressbar" style="width: 0%"></div>
                                </div>
                                <div class="text-center mt-2 small text-muted" id="progressText">Initializing...</div>
                            </div>

                            <!-- Summary Stats -->
                            <div class="row g-3 mb-4 text-center">
                                <div class="col-6 col-md-3">
                                    <div class="result-card p-3 rounded bg-white border h-100">
                                        <div class="text-primary h2 mb-0 fw-bold" id="statTotal">0</div>
                                        <div class="text-muted small fw-bold text-uppercase">Total</div>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="result-card p-3 rounded bg-white border h-100">
                                        <div class="text-success h2 mb-0 fw-bold" id="statSuccess">0</div>
                                        <div class="text-muted small fw-bold text-uppercase">Sent</div>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="result-card p-3 rounded bg-white border h-100">
                                        <div class="text-danger h2 mb-0 fw-bold" id="statFailed">0</div>
                                        <div class="text-muted small fw-bold text-uppercase">Failed</div>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="result-card p-3 rounded bg-white border h-100">
                                        <div class="text-warning h2 mb-0 fw-bold" id="statFallback">0</div>
                                        <div class="text-muted small fw-bold text-uppercase">SMS Fallback</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Failures Table -->
                            <div class="d-flex align-items-center mb-3">
                                <h6 class="mb-0 text-danger fw-bold"><i class="fas fa-exclamation-circle me-2"></i>Failed Deliveries</h6>
                                <span class="badge bg-danger ms-2" id="failureBadge" style="display:none;">0</span>
                            </div>
                            
                            <div class="table-responsive rounded border">
                                <table class="table table-hover mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th class="border-bottom-0">Name</th>
                                            <th class="border-bottom-0">Phone</th>
                                            <th class="border-bottom-0">Error</th>
                                            <th class="border-bottom-0 text-end">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="failuresTableBody">
                                        <!-- Populated via JS -->
                                    </tbody>
                                </table>
                                <div id="noFailuresMsg" class="text-center py-5 bg-white d-none">
                                    <div class="mb-3">
                                        <i class="fas fa-check-circle text-success fa-3x"></i>
                                    </div>
                                    <h5 class="fw-bold text-dark">Success!</h5>
                                    <p class="text-muted mb-0">All messages were sent successfully.</p>
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
    let batchSize = 10;
    let donorsListPage = 1;
    let donorsListLimit = 20;
    let donorsListTotal = 0;

    // Elements
    const step1 = document.getElementById('step1');
    const step2 = document.getElementById('step2');
    const step3 = document.getElementById('step3');
    const step1Ind = document.getElementById('step1-indicator');
    const step2Ind = document.getElementById('step2-indicator');
    const step3Ind = document.getElementById('step3-indicator');
    const line1 = document.getElementById('line1');
    const line2 = document.getElementById('line2');

    const filterForm = document.getElementById('filterForm');
    const btnRefreshCount = document.getElementById('btnRefreshCount');
    const donorCount = document.getElementById('donorCount');
    const btnNextStep = document.getElementById('btnNextStep');
    const btnBackToFilter = document.getElementById('btnBackToFilter');
    const btnSend = document.getElementById('btnSend');
    
    const messageContent = document.getElementById('messageContent');
    const charCount = document.getElementById('charCount');
    const templateSelect = document.getElementById('templateSelect');
    
    // Donor List Elements
    const collapseDonors = document.getElementById('collapseDonors');
    const donorsListContainer = document.getElementById('donorsListContainer');
    const btnLoadMore = document.getElementById('btnLoadMore');
    const loadMoreContainer = document.getElementById('loadMoreContainer');
    const donorsEmptyState = document.getElementById('donorsEmptyState');
    const donorsLoading = document.getElementById('donorsLoading');

    // Initial Count
    fetchDonorCount();

    // Event Listeners
    btnRefreshCount.addEventListener('click', () => {
        fetchDonorCount();
        resetDonorsList();
        if (collapseDonors.classList.contains('show')) {
            fetchDonorsList();
        }
    });
    
    // Auto-refresh count when filters change
    filterForm.querySelectorAll('select, input').forEach(input => {
        input.addEventListener('change', () => {
            fetchDonorCount();
            resetDonorsList();
            if (collapseDonors.classList.contains('show')) {
                fetchDonorsList();
            }
        });
    });
    
    // Load list when accordion opens
    collapseDonors.addEventListener('show.bs.collapse', () => {
        if (donorsListContainer.children.length === 0 && totalDonors > 0) {
            fetchDonorsList();
        }
    });
    
    btnLoadMore.addEventListener('click', () => {
        donorsListPage++;
        fetchDonorsList();
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
            messageContent.value = selected.dataset.en; 
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
        line1.classList.remove('active');
        line2.classList.remove('active');

        if (step === 1) {
            step1.classList.remove('d-none');
            step1Ind.classList.add('active');
        } else if (step === 2) {
            step2.classList.remove('d-none');
            step1Ind.classList.add('completed');
            line1.classList.add('active');
            step2Ind.classList.add('active');
        } else if (step === 3) {
            step3.classList.remove('d-none');
            step1Ind.classList.add('completed');
            step2Ind.classList.add('completed');
            line1.classList.add('active');
            line2.classList.add('active');
            step3Ind.classList.add('active');
        }
    }

    async function fetchDonorCount() {
        const formData = new FormData(filterForm);
        const params = new URLSearchParams(formData);
        
        donorCount.innerHTML = '<i class="fas fa-spinner fa-spin fa-sm"></i>';
        btnNextStep.disabled = true;

        try {
            const response = await fetch('bulk-send-process.php?action=count&' + params.toString());
            const data = await response.json();
            
            if (data.success) {
                totalDonors = parseInt(data.count);
                donorCount.textContent = totalDonors;
                btnNextStep.disabled = totalDonors === 0;
            } else {
                donorCount.textContent = 'Error';
                console.error(data.message);
            }
        } catch (e) {
            console.error(e);
            donorCount.textContent = 'Error';
        }
    }
    
    function resetDonorsList() {
        donorsListContainer.innerHTML = '';
        donorsListPage = 1;
        loadMoreContainer.style.display = 'none';
        donorsEmptyState.style.display = 'none';
    }
    
    async function fetchDonorsList() {
        const formData = new FormData(filterForm);
        const params = new URLSearchParams(formData);
        params.append('page', donorsListPage);
        params.append('limit', donorsListLimit);
        
        donorsLoading.style.display = 'block';
        loadMoreContainer.style.display = 'none'; // Hide button while loading
        
        try {
            const response = await fetch('bulk-send-process.php?action=get_donors_list&' + params.toString());
            const data = await response.json();
            
            donorsLoading.style.display = 'none';
            
            if (data.success) {
                if (data.donors.length === 0 && donorsListPage === 1) {
                    donorsEmptyState.style.display = 'block';
                    return;
                }
                
                donorsEmptyState.style.display = 'none';
                
                data.donors.forEach(donor => {
                    const el = document.createElement('div');
                    el.className = 'donor-list-item d-flex justify-content-between align-items-center';
                    el.innerHTML = `
                        <div>
                            <div class="fw-bold text-dark">${escapeHtml(donor.name)}</div>
                            <div class="small text-muted">
                                <i class="fas fa-phone me-1"></i>${escapeHtml(donor.phone)}
                                ${donor.city ? `<span class="ms-2"><i class="fas fa-map-marker-alt me-1"></i>${escapeHtml(donor.city)}</span>` : ''}
                            </div>
                        </div>
                        <div class="text-end">
                            ${donor.amount ? `<span class="badge bg-light text-dark border">£${parseFloat(donor.amount).toFixed(2)}</span>` : ''}
                            <span class="badge bg-${getStatusColor(donor.payment_status)} ms-1">${formatStatus(donor.payment_status)}</span>
                        </div>
                    `;
                    donorsListContainer.appendChild(el);
                });
                
                // Show Load More if we received a full page, implies more might exist
                // Ideally backend sends total list count, but we can infer:
                if (data.donors.length === donorsListLimit) {
                    loadMoreContainer.style.display = 'block';
                } else {
                    loadMoreContainer.style.display = 'none';
                }
            }
        } catch (e) {
            console.error(e);
            donorsLoading.style.display = 'none';
            donorsListContainer.innerHTML += '<div class="text-danger p-3 text-center">Error loading list.</div>';
        }
    }

    function updateCharCount() {
        const len = messageContent.value.length;
        charCount.textContent = len + ' chars';
    }

    function updateSummary() {
        const channel = document.querySelector('input[name="channel"]:checked').value;
        document.getElementById('summaryChannel').textContent = channel === 'whatsapp' ? 'WhatsApp' : 'SMS Only';
        document.getElementById('summaryChannel').className = channel === 'whatsapp' ? 'fw-bold text-success' : 'fw-bold text-info';
        
        // Very rough estimate
        const costPerMsg = channel === 'whatsapp' ? 0.05 : 0.04; 
        document.getElementById('summaryCost').textContent = '£' + (totalDonors * costPerMsg).toFixed(2);
    }
    
    function escapeHtml(text) {
        if (!text) return '';
        return text
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }
    
    function getStatusColor(status) {
        if (!status) return 'secondary';
        if (status === 'active' || status === 'completed') return 'success';
        if (status === 'overdue' || status === 'failed') return 'danger';
        if (status === 'not_started') return 'warning';
        return 'secondary';
    }
    
    function formatStatus(status) {
        if (!status) return 'Unknown';
        return status.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
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
            const progressPercent = document.getElementById('progressPercent');
            const progressText = document.getElementById('progressText');
            const failuresBody = document.getElementById('failuresTableBody');
            const failureBadge = document.getElementById('failureBadge');
            
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
                                <td><span class="fw-bold">${res.name}</span></td>
                                <td>${res.phone}</td>
                                <td class="text-danger small">${res.error || 'Unknown'}</td>
                                <td class="text-end">
                                    <a href="../call-center/make-call.php?donor_id=${res.donor_id}" target="_blank" class="btn btn-sm btn-outline-primary rounded-pill">
                                        <i class="fas fa-phone-alt"></i> Call
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
                
                if (failed > 0) {
                    failureBadge.style.display = 'inline-block';
                    failureBadge.textContent = failed;
                }
                
                // Update Progress
                const percent = Math.round(((i + batch.length) / total) * 100);
                progressBar.style.width = percent + '%';
                progressPercent.textContent = percent + '%';
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
