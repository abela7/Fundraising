<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

try {
    $db = db();
    $user_id = (int)$_SESSION['user']['id'];
    $user_name = $_SESSION['user']['name'] ?? 'Agent';
    
    // Get user's phone number for Twilio
    $user_phone_query = "SELECT phone, phone_number, email FROM users WHERE id = ?";
    $stmt = $db->prepare($user_phone_query);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $user_result = $stmt->get_result();
    $user_data = $user_result->fetch_assoc();
    $stmt->close();
    
    $user_phone = $user_data['phone_number'] ?? $user_data['phone'] ?? '';
    
    // Get donor_id and queue_id from URL
    $donor_id = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0;
    $queue_id = isset($_GET['queue_id']) ? (int)$_GET['queue_id'] : 0;
    
    if (!$donor_id) {
        header('Location: ../donor-management/donors.php');
        exit;
    }
    
    // If queue_id is 0, donor is not in queue - that's okay, we can still call them
    
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
            COALESCE(
                (SELECT name FROM users WHERE id = d.registered_by_user_id LIMIT 1),
                (SELECT u.name FROM pledges p 
                 JOIN users u ON p.created_by_user_id = u.id 
                 WHERE p.donor_id = d.id 
                 ORDER BY p.created_at DESC LIMIT 1),
                (SELECT u.name FROM payments pay 
                 JOIN users u ON pay.received_by_user_id = u.id 
                 WHERE pay.donor_id = d.id 
                 ORDER BY pay.created_at DESC LIMIT 1)
            ) as registrar_name,
            (SELECT created_at FROM pledges WHERE donor_id = d.id AND status = 'approved' ORDER BY created_at DESC LIMIT 1) as pledge_date,
            (SELECT call_started_at FROM call_center_sessions WHERE donor_id = d.id ORDER BY call_started_at DESC LIMIT 1) as last_call_date
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
        header('Location: ../donor-management/donors.php');
        exit;
    }
    
    // Get actual call count and history
    $call_history_query = "
        SELECT s.*, u.name as agent_name 
        FROM call_center_sessions s
        LEFT JOIN users u ON s.agent_id = u.id
        WHERE s.donor_id = ? 
        ORDER BY s.call_started_at DESC
    ";
    $stmt = $db->prepare($call_history_query);
    $stmt->bind_param('i', $donor_id);
    $stmt->execute();
    $history_result = $stmt->get_result();
    $total_calls = $history_result->num_rows;
    
    $call_history = [];
    while ($row = $history_result->fetch_object()) {
        $call_history[] = $row;
    }
    $stmt->close();
    
    $is_first_call = $total_calls == 0;
    
    // Get last call details
    $last_call = $total_calls > 0 ? $call_history[0] : null;
    
    // Format last contact
    $last_contact = 'Never';
    $last_outcome = 'N/A';
    
    if ($last_call) {
        $last_contact = date('M j, Y g:i A', strtotime($last_call->call_started_at));
        $last_outcome = ucwords(str_replace('_', ' ', $last_call->outcome ?? 'Unknown'));
    } elseif (!empty($donor->last_contacted_at)) {
        $last_contact = date('M j, Y', strtotime($donor->last_contacted_at));
    }
    
} catch (Exception $e) {
    error_log("Make Call Error: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine());
    // Show error instead of redirecting silently
    die("Error loading donor information: " . htmlspecialchars($e->getMessage()) . ". <a href='../donor-management/donors.php'>Go back</a>");
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
        /* Pre-Call Briefing - Modern Design */
        .call-prep-page {
            max-width: 800px;
            margin: 0 auto;
            padding: 0;
        }
        
        /* Donor Name Header - Project Theme */
        .donor-name-header {
            background: #0a6286; /* var(--primary) */
            color: white;
            padding: 1.25rem 1rem;
            border-radius: 12px;
            margin-bottom: 1rem;
            text-align: center;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }
        
        .donor-name-header .phone-link {
            color: white !important;
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.2);
            text-decoration: none;
        }
        
        .donor-name-header .phone-link:hover {
            color: white !important;
            background: rgba(255, 255, 255, 0.25);
            text-decoration: none;
        }
        
        .donor-name-header .phone-link:visited,
        .donor-name-header .phone-link:active,
        .donor-name-header .phone-link:focus {
            color: white !important;
            text-decoration: none;
        }
        
        /* Info Grid - Compact */
        .info-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }
        
        .info-card {
            background: white;
            border: 1px solid var(--cc-border);
            border-radius: 10px;
            padding: 0.875rem;
            transition: all 0.2s;
            box-shadow: var(--cc-shadow);
        }
        
        .info-card:hover {
            box-shadow: var(--cc-shadow-lg);
        }
        
        .info-card-header {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--cc-border);
        }
        
        .info-card-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }
        
        .info-card-icon.primary {
            background: #eff6ff;
            color: var(--cc-primary);
        }
        
        .info-card-icon.success {
            background: #d1fae5;
            color: var(--cc-success);
        }
        
        .info-card-icon.warning {
            background: #fef3c7;
            color: var(--cc-warning);
        }
        
        .info-card-title {
            font-size: 0.75rem;
            font-weight: 600;
            color: #64748b;
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .info-card-body {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.25rem 0;
        }
        
        .info-label {
            color: #64748b;
            font-size: 0.8125rem;
            font-weight: 500;
        }
        
        .info-value {
            color: var(--cc-dark);
            font-weight: 600;
            font-size: 0.875rem;
            text-align: right;
        }
        
        /* Pledge Highlight - Project Theme */
        .pledge-highlight {
            background: white;
            border: 2px solid #ef4444;
            color: #ef4444;
            padding: 1.25rem 1rem;
            border-radius: 12px;
            text-align: center;
            margin: 1rem 0;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }
        
        .pledge-highlight .label {
            color: #64748b;
            font-size: 0.75rem;
            margin-bottom: 0.5rem;
            font-weight: 600;
            letter-spacing: 1px;
            text-transform: uppercase;
        }
        
        .pledge-highlight .amount {
            font-size: 2rem;
            font-weight: 800;
            line-height: 1;
        }
        
        /* Action Buttons - Compact */
        .action-buttons-form {
            margin-top: 1.25rem;
        }
        
        .action-buttons {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.625rem;
        }
        
        .action-buttons .btn {
            padding: 0.75rem 1.25rem;
            font-size: 0.9375rem;
            font-weight: 600;
            border-radius: 10px;
            transition: all 0.2s;
            border-width: 2px;
        }
        
        .action-buttons .btn-success {
            background: var(--cc-success);
            border-color: var(--cc-success);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }
        
        .action-buttons .btn-success:hover {
            background: #059669;
            border-color: #059669;
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(16, 185, 129, 0.4);
        }
        
        .action-buttons .btn-outline-secondary {
            background: white;
            border-color: var(--cc-border);
            color: var(--cc-dark);
        }
        
        .action-buttons .btn-outline-secondary:hover {
            background: var(--cc-light);
            border-color: #cbd5e1;
        }
        
        /* Tablet - 2 columns for info cards */
        @media (min-width: 576px) {
            .donor-name-header h2 {
                font-size: 1.5rem;
            }
            
            .info-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .pledge-highlight .amount {
                font-size: 2.5rem;
            }
            
            .action-buttons {
                grid-template-columns: auto 1fr;
            }
            
            .action-buttons .btn:first-child {
                min-width: 120px;
            }
        }
        
        /* Desktop */
        @media (min-width: 768px) {
            .donor-name-header {
                padding: 1.5rem 1.25rem;
            }
            
            .donor-name-header h2 {
                font-size: 1.625rem;
            }
            
            .info-card {
                padding: 1rem;
            }
            
            .info-card-icon {
                width: 36px;
                height: 36px;
                font-size: 1.125rem;
            }
            
            .pledge-highlight {
                padding: 1.5rem 1.25rem;
            }
            
            .pledge-highlight .amount {
                font-size: 2.75rem;
            }
        }
        
        /* Large Desktop */
        @media (min-width: 992px) {
            .info-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        
        /* First Call Badge - Project Theme */
        .first-call-badge {
            display: inline-block;
            background: #3b82f6; /* Info color */
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.8125rem;
            font-weight: 600;
            margin-bottom: 0.875rem;
            box-shadow: 0 2px 4px rgba(59, 130, 246, 0.2);
        }
        
        .first-call-badge i {
            margin-right: 0.375rem;
            font-size: 0.75rem;
        }

        /* History Modal */
        .history-timeline {
            position: relative;
            padding-left: 1.5rem;
            border-left: 2px solid #e2e8f0;
            margin: 1rem 0 1rem 0.5rem;
        }

        .history-item {
            position: relative;
            margin-bottom: 1.5rem;
        }

        .history-item:last-child {
            margin-bottom: 0;
        }

        .history-dot {
            position: absolute;
            left: -1.95rem;
            top: 0.25rem;
            width: 1rem;
            height: 1rem;
            border-radius: 50%;
            background: white;
            border: 2px solid #0a6286;
        }

        .history-content {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1rem;
            border: 1px solid #e2e8f0;
        }

        .history-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
            font-size: 0.8125rem;
            color: #64748b;
        }

        .history-outcome {
            font-weight: 700;
            color: #0a6286;
            margin-bottom: 0.25rem;
        }

        .history-notes {
            font-size: 0.875rem;
            color: #334155;
            margin-top: 0.5rem;
            padding-top: 0.5rem;
            border-top: 1px solid #e2e8f0;
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        
        <main class="main-content">
            <!-- Hidden CSRF token for AJAX calls -->
            <?php require_once __DIR__ . '/../../shared/csrf.php'; echo csrf_input(); ?>
            
            <div class="call-prep-page">
                <?php if ($is_first_call): ?>
                    <div class="first-call-badge">
                        <i class="fas fa-star"></i>
                        First Time Call - This is the first contact with this donor
                    </div>
                <?php endif; ?>
                
                <!-- Donor Name Header -->
                <div class="donor-name-header">
                    <h2>
                        <i class="fas fa-user-circle"></i>
                        <?php echo htmlspecialchars($donor->name); ?>
                    </h2>
                    <a href="tel:<?php echo htmlspecialchars($donor->phone); ?>" class="phone-link">
                        <i class="fas fa-phone"></i>
                        <?php echo htmlspecialchars($donor->phone); ?>
                    </a>
                </div>
                
                <!-- Info Grid -->
                <div class="info-grid">
                    <!-- Contact History Card -->
                    <div class="info-card">
                        <div class="info-card-header">
                            <div class="info-card-icon primary">
                                <i class="fas fa-history"></i>
                            </div>
                            <h3 class="info-card-title">Contact History</h3>
                        </div>
                        <div class="info-card-body">
                            <div class="info-item">
                                <span class="info-label">Attempts</span>
                                <span class="badge bg-info"><?php echo $total_calls; ?> calls</span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Last Contact</span>
                                <span class="info-value"><?php echo htmlspecialchars($last_contact); ?></span>
                            </div>
                            <?php if ($last_call): ?>
                            <div class="info-item">
                                <span class="info-label">Last Outcome</span>
                                <span class="info-value"><?php echo htmlspecialchars($last_outcome); ?></span>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary w-100 mt-2" data-bs-toggle="modal" data-bs-target="#historyModal">
                                <i class="fas fa-list-ul me-2"></i>View Call History
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Location Card -->
                    <div class="info-card">
                        <div class="info-card-header">
                            <div class="info-card-icon success">
                                <i class="fas fa-map-marker-alt"></i>
                            </div>
                            <h3 class="info-card-title">Location</h3>
                        </div>
                        <div class="info-card-body">
                            <div class="info-item">
                                <span class="info-label">City</span>
                                <span class="info-value"><?php echo !empty($donor->city) ? htmlspecialchars($donor->city) : '<span class="text-muted">Not specified</span>'; ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Registration Card -->
                    <div class="info-card">
                        <div class="info-card-header">
                            <div class="info-card-icon warning">
                                <i class="fas fa-user-tag"></i>
                            </div>
                            <h3 class="info-card-title">Registration</h3>
                        </div>
                        <div class="info-card-body">
                            <div class="info-item">
                                <span class="info-label">Registered By</span>
                                <span class="info-value">
                                    <?php 
                                    if (!empty($donor->registrar_name)) {
                                        echo htmlspecialchars($donor->registrar_name);
                                    } else {
                                        echo '<span class="text-danger" style="font-size: 0.75rem;">Not found</span>';
                                    }
                                    ?>
                                </span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Date</span>
                                <span class="info-value"><?php echo date('M j, Y', strtotime($donor->created_at)); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="pledge-highlight">
                    <div class="label">Pledged Amount</div>
                    <div class="amount">£<?php echo number_format((float)$donor->balance, 2); ?></div>
                </div>
                
                <div class="action-buttons">
                    <!-- Twilio Click-to-Call Button -->
                    <?php if (!empty($user_phone)): ?>
                    <button type="button" class="btn btn-primary btn-lg" id="twilioCallBtn" onclick="initiateTwilioCallDirect()">
                        <i class="fas fa-phone-volume me-2"></i>Call via Twilio
                        <small class="d-block" style="font-size: 0.7rem; opacity: 0.9;">Your phone (<?php echo htmlspecialchars($user_phone); ?>) will ring first</small>
                    </button>
                    <?php else: ?>
                    <button type="button" class="btn btn-primary btn-lg" onclick="showTwilioCallModal()">
                        <i class="fas fa-phone-volume me-2"></i>Call via Twilio
                        <small class="d-block" style="font-size: 0.7rem; opacity: 0.9;">Enter your phone number</small>
                    </button>
                    <?php endif; ?>
                    
                    <div class="text-center my-2">
                        <span class="badge bg-light text-muted">OR</span>
                    </div>
                    
                    <?php if($queue_id > 0): ?>
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to Queue
                    </a>
                    <?php else: ?>
                    <a href="../donor-management/donors.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to List
                    </a>
                    <?php endif; ?>
                    <a href="call-status.php?donor_id=<?php echo $donor_id; ?>&queue_id=<?php echo $queue_id; ?>" 
                       class="btn btn-outline-success btn-lg">
                        <i class="fas fa-phone-alt me-2"></i>Manual Call (Track Timer)
                    </a>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Call History Modal -->
<div class="modal fade" id="historyModal" tabindex="-1" aria-labelledby="historyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <h5 class="modal-title" id="historyModalLabel">
                    <i class="fas fa-history me-2 text-primary"></i>Call History
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="history-timeline">
                    <?php foreach ($call_history as $call): ?>
                        <?php 
                            $call_date = date('M j, Y', strtotime($call->call_started_at));
                            $call_time = date('g:i A', strtotime($call->call_started_at));
                            $outcome_label = ucwords(str_replace('_', ' ', $call->outcome ?? 'Unknown'));
                        ?>
                        <div class="history-item">
                            <div class="history-dot"></div>
                            <div class="history-content">
                                <div class="history-meta">
                                    <span><i class="far fa-calendar me-1"></i><?php echo $call_date; ?> at <?php echo $call_time; ?></span>
                                    <span><i class="far fa-user me-1"></i><?php echo htmlspecialchars($call->agent_name ?? 'Unknown'); ?></span>
                                </div>
                                <div class="history-outcome">
                                    <?php echo $outcome_label; ?>
                                </div>
                                <?php if (!empty($call->notes)): ?>
                                    <div class="history-notes">
                                        <?php echo nl2br(htmlspecialchars($call->notes)); ?>
                                    </div>
                                <?php endif; ?>
                                <div class="mt-2 text-end">
                                    <a href="call-details.php?id=<?php echo $call->id; ?>" class="btn btn-sm btn-link text-decoration-none p-0">
                                        View Details <i class="fas fa-arrow-right ms-1"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Twilio Call Modal -->
<div class="modal fade" id="twilioCallModal" tabindex="-1" aria-labelledby="twilioCallModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="twilioCallModalLabel">
                    <i class="fas fa-phone-volume me-2"></i>Call via Twilio
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>How it works:</strong>
                    <ol class="mb-0 mt-2" style="font-size: 0.875rem;">
                        <li>Enter your phone number below</li>
                        <li>Click "Start Call" - your phone will ring</li>
                        <li>Answer your phone</li>
                        <li>You'll hear "Connecting to <?php echo htmlspecialchars(explode(' ', $donor->name)[0]); ?>..."</li>
                        <li>Donor's phone will ring and you'll be connected!</li>
                    </ol>
                </div>
                
                <form id="twilioCallForm">
                    <?php require_once __DIR__ . '/../../shared/csrf.php'; echo csrf_input(); ?>
                    <input type="hidden" name="donor_id" value="<?php echo $donor_id; ?>">
                    <input type="hidden" name="queue_id" value="<?php echo $queue_id; ?>">
                    
                    <div class="mb-3">
                        <label for="agentPhone" class="form-label fw-semibold">
                            Your Phone Number <span class="text-danger">*</span>
                        </label>
                        <input type="tel" class="form-control form-control-lg" id="agentPhone" name="agent_phone" 
                               placeholder="07XXXXXXXXX or +447XXXXXXXXX" required
                               pattern="^(07[0-9]{9}|447[0-9]{9}|\+447[0-9]{9})$">
                        <div class="form-text">
                            <i class="fas fa-lock me-1"></i>UK mobile format (e.g., 07123456789)
                        </div>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Important:</strong> Make sure your phone is nearby and the volume is turned on!
                    </div>
                </form>
                
                <div id="twilioCallStatus" class="mt-3" style="display: none;">
                    <!-- Status messages will appear here -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary btn-lg" onclick="initiateTwilioCall()">
                    <i class="fas fa-phone-volume me-2"></i>Start Call
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
// Twilio Click-to-Call Functions
let twilioModal = null;
let callStatusInterval = null;
let currentSessionId = null;

// Direct call function (no modal) - uses logged-in user's phone
function initiateTwilioCallDirect() {
    const btn = document.getElementById('twilioCallBtn');
    const originalHTML = btn.innerHTML;
    
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Initiating Call...';
    
    showToast('📞 Initiating call...', 'info', 'Your phone will ring shortly');
    
    // Prepare form data
    const formData = new FormData();
    formData.append('donor_id', <?php echo $donor_id; ?>);
    formData.append('queue_id', <?php echo $queue_id; ?>);
    formData.append('agent_phone', '<?php echo htmlspecialchars($user_phone); ?>');
    formData.append('csrf_token', document.querySelector('input[name="csrf_token"]')?.value || '');
    
    fetch('api/twilio-initiate-call.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            currentSessionId = data.session_id;
            showToast('✅ Call Started!', 'success', 'Your phone is ringing now...');
            
            // Start polling for call status (for notifications only)
            startCallStatusPolling(data.session_id);
            
            // Redirect to call-status.php immediately
            setTimeout(() => {
                window.location.href = 'call-status.php?donor_id=<?php echo $donor_id; ?>&queue_id=<?php echo $queue_id; ?>&session_id=' + data.session_id;
            }, 1500);
        } else {
            throw new Error(data.error || 'Failed to initiate call');
        }
    })
    .catch(error => {
        console.error('Twilio call error:', error);
        showToast('❌ Call Failed', 'error', error.message);
        btn.disabled = false;
        btn.innerHTML = originalHTML;
    });
}

// Modal-based call (fallback for users without phone number)
function showTwilioCallModal() {
    if (!twilioModal) {
        twilioModal = new bootstrap.Modal(document.getElementById('twilioCallModal'));
    }
    twilioModal.show();
}

function initiateTwilioCall() {
    const form = document.getElementById('twilioCallForm');
    const agentPhone = document.getElementById('agentPhone').value.trim();
    const statusDiv = document.getElementById('twilioCallStatus');
    const submitBtn = event.target;
    
    if (!agentPhone) {
        showTwilioStatus('error', 'Please enter your phone number');
        return;
    }
    
    const originalHTML = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Initiating Call...';
    
    showTwilioStatus('info', '📞 Calling your phone...');
    
    const formData = new FormData(form);
    
    fetch('api/twilio-initiate-call.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            currentSessionId = data.session_id;
            showTwilioStatus('success', '✅ ' + data.message);
            startCallStatusPolling(data.session_id);
            
            setTimeout(() => {
                window.location.href = 'call-status.php?donor_id=<?php echo $donor_id; ?>&queue_id=<?php echo $queue_id; ?>&session_id=' + data.session_id;
            }, 1500);
        } else {
            throw new Error(data.error || 'Failed to initiate call');
        }
    })
    .catch(error => {
        console.error('Twilio call error:', error);
        showTwilioStatus('error', '❌ ' + error.message);
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalHTML;
    });
}

function showTwilioStatus(type, message) {
    const statusDiv = document.getElementById('twilioCallStatus');
    const alertClass = type === 'error' ? 'alert-danger' : 
                       type === 'success' ? 'alert-success' : 'alert-info';
    
    statusDiv.innerHTML = `<div class="alert ${alertClass} mb-0">${message}</div>`;
    statusDiv.style.display = 'block';
}

// Call Status Polling
function startCallStatusPolling(sessionId) {
    if (callStatusInterval) {
        clearInterval(callStatusInterval);
    }
    
    let lastStatus = '';
    
    callStatusInterval = setInterval(() => {
        fetch('api/get-call-status.php?session_id=' + sessionId)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.status !== lastStatus) {
                    lastStatus = data.status;
                    handleCallStatusUpdate(data);
                }
            })
            .catch(error => console.error('Status polling error:', error));
    }, 2000); // Poll every 2 seconds
}

function handleCallStatusUpdate(data) {
    const status = data.status;
    const twilioStatus = data.twilio_status;
    
    // ONLY show toast notifications - NO automatic actions
    if (twilioStatus === 'ringing') {
        showToast('📱 Your Phone Ringing', 'info', 'Answer your phone to continue');
    } else if (twilioStatus === 'in-progress' || twilioStatus === 'answered') {
        showToast('✅ You Picked Up!', 'success', 'Connecting to donor now...');
    } else if (twilioStatus === 'completed') {
        showToast('📞 Donor Answered!', 'success', 'Click "Picked Up" to start conversation');
        // Stop polling - call is connected
        if (callStatusInterval) {
            clearInterval(callStatusInterval);
        }
    } else if (twilioStatus === 'failed' || twilioStatus === 'busy' || twilioStatus === 'no-answer') {
        showToast('❌ Call Failed', 'error', 'Could not connect the call');
        if (callStatusInterval) {
            clearInterval(callStatusInterval);
        }
    }
}

// Mobile-friendly Toast Notifications
function showToast(title, type, message) {
    // Remove existing toast container if any
    const existingContainer = document.getElementById('toastContainer');
    if (existingContainer) {
        existingContainer.remove();
    }
    
    // Create toast container
    const container = document.createElement('div');
    container.id = 'toastContainer';
    container.className = 'position-fixed top-0 end-0 p-3';
    container.style.zIndex = '9999';
    
    const bgClass = type === 'error' ? 'bg-danger' : 
                    type === 'success' ? 'bg-success' : 'bg-info';
    
    const icon = type === 'error' ? '❌' : 
                 type === 'success' ? '✅' : '📞';
    
    container.innerHTML = `
        <div class="toast show ${bgClass} text-white" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="toast-header ${bgClass} text-white border-0">
                <span class="me-2" style="font-size: 1.25rem;">${icon}</span>
                <strong class="me-auto">${title}</strong>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
            <div class="toast-body" style="font-size: 0.95rem;">
                ${message}
            </div>
        </div>
    `;
    
    document.body.appendChild(container);
    
    // Auto-remove after 5 seconds (unless it's an error)
    if (type !== 'error') {
        setTimeout(() => {
            container.remove();
        }, 5000);
    }
}

// Cleanup on page unload
window.addEventListener('beforeunload', () => {
    if (callStatusInterval) {
        clearInterval(callStatusInterval);
    }
});
</script>
</body>
</html>
