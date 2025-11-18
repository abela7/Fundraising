<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

try {
    $db = db();
    $user_id = (int)$_SESSION['user']['id'];
    $user_name = $_SESSION['user']['name'] ?? 'Agent';
    
    // Get donor_id and queue_id from URL
    $donor_id = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0;
    $queue_id = isset($_GET['queue_id']) ? (int)$_GET['queue_id'] : 0;
    
    if (!$donor_id || !$queue_id) {
        header('Location: index.php');
        exit;
    }
    
    // Get donor information
    $donor_query = "
        SELECT 
            d.id,
            d.name,
            d.phone,
            d.balance,
            d.city,
            d.created_at,
            d.last_contacted_at,
            q.queue_type,
            q.priority,
            q.attempts_count,
            q.reason_for_queue,
            (SELECT name FROM users WHERE id = d.created_by LIMIT 1) as registrar_name
        FROM donors d
        LEFT JOIN call_center_queues q ON q.donor_id = d.id AND q.id = ?
        WHERE d.id = ?
        LIMIT 1
    ";
    
    $stmt = $db->prepare($donor_query);
    $stmt->bind_param('ii', $queue_id, $donor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $donor = $result->fetch_object();
    $stmt->close();
    
    if (!$donor) {
        header('Location: index.php');
        exit;
    }
    
    // Check if this is truly a first call
    $call_history_query = "SELECT COUNT(*) as call_count FROM call_center_sessions WHERE donor_id = ?";
    $stmt = $db->prepare($call_history_query);
    $stmt->bind_param('i', $donor_id);
    $stmt->execute();
    $history = $stmt->get_result()->fetch_object();
    $stmt->close();
    
    $is_first_call = $history->call_count == 0;
    
} catch (Exception $e) {
    error_log("Make Call Error: " . $e->getMessage());
    header('Location: index.php');
    exit;
}

$page_title = 'Call: ' . $donor->name;
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
    <style>
        /* Call Wizard Styles */
        .call-wizard {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .wizard-progress {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2rem;
            position: relative;
        }
        
        .wizard-progress::before {
            content: '';
            position: absolute;
            top: 20px;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--cc-border);
            z-index: 0;
        }
        
        .wizard-step {
            flex: 1;
            text-align: center;
            position: relative;
            z-index: 1;
        }
        
        .step-circle {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: white;
            border: 2px solid var(--cc-border);
            color: #94a3b8;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.5rem;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .wizard-step.active .step-circle {
            background: var(--cc-primary);
            border-color: var(--cc-primary);
            color: white;
        }
        
        .wizard-step.completed .step-circle {
            background: var(--cc-success);
            border-color: var(--cc-success);
            color: white;
        }
        
        .step-label {
            font-size: 0.75rem;
            color: #64748b;
            font-weight: 500;
        }
        
        .wizard-step.active .step-label {
            color: var(--cc-primary);
            font-weight: 600;
        }
        
        .wizard-content {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: var(--cc-shadow);
            border: 1px solid var(--cc-border);
        }
        
        .wizard-section {
            display: none;
        }
        
        .wizard-section.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .donor-info-card {
            background: #f8fafc;
            border: 1px solid var(--cc-border);
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--cc-border);
        }
        
        .info-row:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }
        
        .info-row:first-child {
            padding-top: 0;
        }
        
        .info-label {
            color: #64748b;
            font-size: 0.875rem;
            font-weight: 500;
        }
        
        .info-value {
            color: var(--cc-dark);
            font-weight: 600;
        }
        
        .pledge-highlight {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
            padding: 1rem;
            border-radius: 8px;
            text-align: center;
            margin: 1rem 0;
        }
        
        .pledge-highlight .amount {
            font-size: 2rem;
            font-weight: 700;
        }
        
        .option-grid {
            display: grid;
            gap: 1rem;
            margin: 1.5rem 0;
        }
        
        .option-card {
            background: white;
            border: 2px solid var(--cc-border);
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .option-card:hover {
            border-color: var(--cc-primary);
            transform: translateY(-2px);
            box-shadow: var(--cc-shadow-lg);
        }
        
        .option-card.selected {
            border-color: var(--cc-primary);
            background: #f0f9ff;
        }
        
        .option-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: #f0f9ff;
            color: var(--cc-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 1.5rem;
        }
        
        .option-card.danger .option-icon {
            background: #fee2e2;
            color: var(--cc-danger);
        }
        
        .option-card.success .option-icon {
            background: #d1fae5;
            color: var(--cc-success);
        }
        
        .option-title {
            font-size: 1.125rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .option-desc {
            font-size: 0.875rem;
            color: #64748b;
        }
        
        .wizard-actions {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid var(--cc-border);
        }
        
        .wizard-actions .btn {
            flex: 1;
        }
        
        @media (max-width: 767px) {
            .wizard-content {
                padding: 1.5rem;
            }
            
            .wizard-progress {
                margin-bottom: 1.5rem;
            }
            
            .step-label {
                font-size: 0.6875rem;
            }
            
            .pledge-highlight .amount {
                font-size: 1.5rem;
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
            <div class="call-wizard">
                <!-- Wizard Progress -->
                <div class="wizard-progress">
                    <div class="wizard-step active" data-step="1">
                        <div class="step-circle">1</div>
                        <div class="step-label">Donor Info</div>
                    </div>
                    <div class="wizard-step" data-step="2">
                        <div class="step-circle">2</div>
                        <div class="step-label">Call Status</div>
                    </div>
                    <div class="wizard-step" data-step="3">
                        <div class="step-circle">3</div>
                        <div class="step-label">Next Action</div>
                    </div>
                </div>
                
                <!-- Wizard Content -->
                <div class="wizard-content">
                    <!-- Step 1: Donor Information -->
                    <div class="wizard-section active" id="step1">
                        <h4 class="mb-3">
                            <i class="fas fa-user-circle me-2"></i>
                            Ready to Call
                        </h4>
                        
                        <?php if ($is_first_call): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>First Time Call</strong> - This is the first contact with this donor.
                            </div>
                        <?php endif; ?>
                        
                        <div class="donor-info-card">
                            <div class="info-row">
                                <span class="info-label">Donor Name</span>
                                <span class="info-value"><?php echo htmlspecialchars($donor->name); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Phone Number</span>
                                <a href="tel:<?php echo htmlspecialchars($donor->phone); ?>" class="info-value text-decoration-none">
                                    <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($donor->phone); ?>
                                </a>
                            </div>
                            <?php if (!empty($donor->city)): ?>
                            <div class="info-row">
                                <span class="info-label">Location</span>
                                <span class="info-value"><?php echo htmlspecialchars($donor->city); ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="info-row">
                                <span class="info-label">Registered By</span>
                                <span class="info-value"><?php echo htmlspecialchars($donor->registrar_name ?? 'Unknown'); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Registration Date</span>
                                <span class="info-value"><?php echo date('M j, Y', strtotime($donor->created_at)); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Call Time</span>
                                <span class="info-value"><?php echo date('l, F j, Y - g:i A'); ?></span>
                            </div>
                        </div>
                        
                        <div class="pledge-highlight">
                            <div style="font-size: 0.875rem; opacity: 0.9; margin-bottom: 0.5rem;">Pledged Amount</div>
                            <div class="amount">£<?php echo number_format((float)$donor->balance, 2); ?></div>
                        </div>
                        
                        <div class="wizard-actions">
                            <a href="index.php" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left me-2"></i>Back to Queue
                            </a>
                            <button class="btn btn-success btn-lg" onclick="startCall()">
                                <i class="fas fa-phone-alt me-2"></i>Start Call
                            </button>
                        </div>
                    </div>
                    
                    <!-- Step 2: Phone Status -->
                    <div class="wizard-section" id="step2">
                        <h4 class="mb-3">
                            <i class="fas fa-phone-volume me-2"></i>
                            Phone Status
                        </h4>
                        
                        <p class="text-muted mb-4">Did the donor pick up the phone?</p>
                        
                        <div class="option-grid">
                            <div class="option-card success" onclick="selectPhoneStatus('picked_up')">
                                <div class="option-icon">
                                    <i class="fas fa-phone-alt"></i>
                                </div>
                                <div class="option-title">Picked Up</div>
                                <div class="option-desc">Donor answered the call</div>
                            </div>
                            
                            <div class="option-card danger" onclick="selectPhoneStatus('not_picked_up')">
                                <div class="option-icon">
                                    <i class="fas fa-phone-slash"></i>
                                </div>
                                <div class="option-title">No Answer</div>
                                <div class="option-desc">Donor didn't pick up</div>
                            </div>
                            
                            <div class="option-card danger" onclick="selectPhoneStatus('busy')">
                                <div class="option-icon">
                                    <i class="fas fa-ban"></i>
                                </div>
                                <div class="option-title">Busy</div>
                                <div class="option-desc">Line was busy</div>
                            </div>
                            
                            <div class="option-card danger" onclick="selectPhoneStatus('not_working')">
                                <div class="option-icon">
                                    <i class="fas fa-times-circle"></i>
                                </div>
                                <div class="option-title">Not Working</div>
                                <div class="option-desc">Number not reachable</div>
                            </div>
                        </div>
                        
                        <div class="wizard-actions">
                            <button class="btn btn-outline-secondary" onclick="goToStep(1)">
                                <i class="fas fa-arrow-left me-2"></i>Back
                            </button>
                        </div>
                    </div>
                    
                    <!-- Step 3: Next Action (Dynamic based on phone status) -->
                    <div class="wizard-section" id="step3">
                        <!-- This will be populated dynamically -->
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
// Call session data
const donorId = <?php echo $donor_id; ?>;
const queueId = <?php echo $queue_id; ?>;
const agentId = <?php echo $user_id; ?>;
let callStartTime = null;
let phoneStatus = null;

// Start the call
function startCall() {
    callStartTime = new Date();
    goToStep(2);
}

// Navigate to step
function goToStep(step) {
    // Update progress
    document.querySelectorAll('.wizard-step').forEach((el, index) => {
        const stepNum = index + 1;
        el.classList.remove('active', 'completed');
        
        if (stepNum < step) {
            el.classList.add('completed');
        } else if (stepNum === step) {
            el.classList.add('active');
        }
    });
    
    // Show section
    document.querySelectorAll('.wizard-section').forEach(el => {
        el.classList.remove('active');
    });
    document.getElementById('step' + step).classList.add('active');
}

// Handle phone status selection
function selectPhoneStatus(status) {
    phoneStatus = status;
    
    if (status === 'picked_up') {
        showAvailabilityCheck();
    } else {
        showScheduler(status);
    }
}

// Show availability check (if picked up)
function showAvailabilityCheck() {
    const step3 = document.getElementById('step3');
    step3.innerHTML = `
        <h4 class="mb-3">
            <i class="fas fa-comments me-2"></i>
            Donor Availability
        </h4>
        
        <p class="text-muted mb-4">Can the donor talk right now?</p>
        
        <div class="option-grid">
            <div class="option-card success" onclick="handleCanTalk()">
                <div class="option-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="option-title">Can Talk</div>
                <div class="option-desc">Donor is available to talk now</div>
            </div>
            
            <div class="option-card" onclick="handleCantTalk()">
                <div class="option-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="option-title">Can't Talk Now</div>
                <div class="option-desc">Donor is busy, schedule callback</div>
            </div>
        </div>
        
        <div class="wizard-actions">
            <button class="btn btn-outline-secondary" onclick="goToStep(2)">
                <i class="fas fa-arrow-left me-2"></i>Back
            </button>
        </div>
    `;
    goToStep(3);
}

// Handle donor can talk
function handleCanTalk() {
    // Will proceed to conversation (next phase)
    alert('Proceeding to conversation phase... (To be implemented)');
}

// Handle donor can't talk
function handleCantTalk() {
    showScheduler('busy_cant_talk');
}

// Show scheduler
function showScheduler(reason) {
    const step3 = document.getElementById('step3');
    
    const reasonText = {
        'not_picked_up': 'No answer - Schedule callback',
        'busy': 'Line busy - Schedule callback',
        'not_working': 'Number not working - Mark for review',
        'busy_cant_talk': 'Donor can\'t talk now - Schedule callback'
    }[reason] || 'Schedule Callback';
    
    step3.innerHTML = `
        <h4 class="mb-3">
            <i class="fas fa-calendar-alt me-2"></i>
            ${reasonText}
        </h4>
        
        <div class="donor-info-card mb-4">
            <div class="info-row">
                <span class="info-label">Call Status</span>
                <span class="info-value">${formatStatus(reason)}</span>
            </div>
        </div>
        
        ${reason !== 'not_working' ? `
        <div class="mb-3">
            <label class="form-label fw-semibold">Select Callback Date</label>
            <input type="date" class="form-control" id="callbackDate" min="${new Date().toISOString().split('T')[0]}" required>
        </div>
        
        <div class="mb-3">
            <label class="form-label fw-semibold">Preferred Time</label>
            <select class="form-select" id="callbackTime" required>
                <option value="">Choose time...</option>
                <option value="morning">Morning (9AM - 12PM)</option>
                <option value="afternoon">Afternoon (12PM - 5PM)</option>
                <option value="evening">Evening (5PM - 8PM)</option>
            </select>
        </div>
        
        <div class="mb-3">
            <label class="form-label fw-semibold">Notes (Optional)</label>
            <textarea class="form-control" id="callbackNotes" rows="3" placeholder="Any additional information..."></textarea>
        </div>
        ` : `
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            This number appears to be not working. Please verify the contact details.
        </div>
        `}
        
        <div class="wizard-actions">
            <button class="btn btn-outline-secondary" onclick="goToStep(2)">
                <i class="fas fa-arrow-left me-2"></i>Back
            </button>
            <button class="btn btn-primary" onclick="saveAndContinue('${reason}')">
                <i class="fas fa-save me-2"></i>Save & Continue
            </button>
        </div>
    `;
    goToStep(3);
}

// Format status for display
function formatStatus(status) {
    const statusMap = {
        'not_picked_up': 'No Answer',
        'busy': 'Line Busy',
        'not_working': 'Number Not Working',
        'busy_cant_talk': 'Picked Up - Can\'t Talk'
    };
    return statusMap[status] || status;
}

// Save and continue
async function saveAndContinue(reason) {
    const callbackDate = document.getElementById('callbackDate')?.value;
    const callbackTime = document.getElementById('callbackTime')?.value;
    const notes = document.getElementById('callbackNotes')?.value || '';
    
    if (reason !== 'not_working' && (!callbackDate || !callbackTime)) {
        alert('Please select both date and time for the callback.');
        return;
    }
    
    const callData = {
        donor_id: donorId,
        queue_id: queueId,
        agent_id: agentId,
        phone_status: phoneStatus,
        reason: reason,
        callback_date: callbackDate || null,
        callback_time: callbackTime || null,
        notes: notes,
        call_started_at: callStartTime.toISOString(),
        call_ended_at: new Date().toISOString()
    };
    
    // For now, just redirect back
    // TODO: Save call data via AJAX
    console.log('Call data:', callData);
    alert('Call logged successfully! Moving to next donor...');
    window.location.href = 'index.php';
}
</script>
</body>
</html>
