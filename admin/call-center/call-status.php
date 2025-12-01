<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

// Set timezone to London for call center (handles GMT/BST automatically)
date_default_timezone_set('Europe/London');

try {
    $db = db();
    $user_id = (int)$_SESSION['user']['id'];
    
    // Get donor_id and queue_id from POST (form submission) or GET (direct access)
    $donor_id = isset($_POST['donor_id']) ? (int)$_POST['donor_id'] : (isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0);
    $queue_id = isset($_POST['queue_id']) ? (int)$_POST['queue_id'] : (isset($_GET['queue_id']) ? (int)$_GET['queue_id'] : 0);
    $session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
    
    if (!$donor_id) {
        header('Location: ../donor-management/donors.php');
        exit;
    }
    
    // Get donor name for display
    $donor_query = "SELECT name, phone FROM donors WHERE id = ? LIMIT 1";
    $stmt = $db->prepare($donor_query);
    $stmt->bind_param('i', $donor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $donor = $result->fetch_object();
    $stmt->close();
    
    if (!$donor) {
        header('Location: ../donor-management/donors.php');
        exit;
    }
    
    // Get call start time
    if ($session_id > 0) {
        // Twilio call - get start time from session
        $session_query = "SELECT call_started_at FROM call_center_sessions WHERE id = ?";
        $stmt = $db->prepare($session_query);
        $stmt->bind_param('i', $session_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $session_data = $result->fetch_assoc();
        $stmt->close();
        
        $call_started_at = $session_data['call_started_at'] ?? date('Y-m-d H:i:s');
    } else {
        // Manual call - record current time
        $now = new DateTime('now', new DateTimeZone('Europe/London'));
        $now->setTimezone(new DateTimeZone('UTC'));
        $call_started_at = $now->format('Y-m-d H:i:s');
    }
    
} catch (Exception $e) {
    error_log("Call Status Error: " . $e->getMessage());
    header('Location: ../donor-management/donors.php');
    exit;
}

$page_title = 'Call Status';
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
        .call-status-page {
            max-width: 700px;
            margin: 0 auto;
            padding: 0.75rem;
        }
        
        .content-header {
            margin-bottom: 1rem;
        }
        
        .content-title {
            font-size: 1.375rem;
            font-weight: 700;
            color: #0a6286;
            margin: 0;
        }
        
        .content-subtitle {
            font-size: 0.875rem;
            color: #64748b;
            margin: 0.25rem 0 0 0;
        }
        
        .donor-header {
            background: #0a6286;
            color: white;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
            text-align: center;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        
        .donor-header h4 {
            margin: 0 0 0.375rem 0;
            font-size: 1.125rem;
            font-weight: 700;
        }
        
        .donor-header .phone {
            font-size: 0.875rem;
            opacity: 0.95;
        }
        
        .option-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.875rem;
            margin: 1rem 0;
        }
        
        .option-card {
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 1.25rem 1rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.25s;
            text-decoration: none;
            color: inherit;
            display: block;
        }
        
        .option-card:hover {
            border-color: #0a6286;
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            text-decoration: none;
            color: inherit;
        }
        
        .option-card.success:hover {
            border-color: #22c55e;
        }
        
        .option-card.danger:hover {
            border-color: #ef4444;
        }
        
        .option-icon {
            width: 55px;
            height: 55px;
            border-radius: 50%;
            background: #f0f9ff;
            color: #0a6286;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.75rem;
            font-size: 1.5rem;
        }
        
        .option-card.danger .option-icon {
            background: #fef2f2;
            color: #ef4444;
        }
        
        .option-card.success .option-icon {
            background: #f0fdf4;
            color: #22c55e;
        }
        
        .option-title {
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            color: #1e293b;
        }
        
        .option-desc {
            font-size: 0.8125rem;
            color: #64748b;
            margin: 0;
        }
        
        .action-buttons {
            display: flex;
            gap: 0.75rem;
            margin-top: 1rem;
        }
        
        .action-buttons .btn {
            flex: 1;
            padding: 0.625rem 1rem;
            font-size: 0.9375rem;
            font-weight: 600;
        }
        
        @media (max-width: 767px) {
            .call-status-page {
                padding: 0.5rem;
            }
            
            .content-title {
                font-size: 1.25rem;
            }
            
            .donor-header {
                padding: 0.875rem;
            }
            
            .option-grid {
                grid-template-columns: 1fr;
                gap: 0.75rem;
            }
            
            .option-card {
                padding: 1.125rem 1rem;
            }
            
            .option-icon {
                width: 50px;
                height: 50px;
                font-size: 1.375rem;
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
            <div class="call-status-page">
                <div class="content-header mb-4">
                    <h1 class="content-title">
                        <i class="fas fa-phone-volume me-2"></i>
                        Phone Status
                    </h1>
                    <p class="content-subtitle">What happened when you called?</p>
                </div>
                
                <div class="donor-header">
                    <h4><?php echo htmlspecialchars($donor->name); ?></h4>
                    <div class="phone">
                        <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($donor->phone); ?>
                    </div>
                </div>
                
                <div class="option-grid">
                    <?php 
                    $common_params = "donor_id={$donor_id}&queue_id={$queue_id}&call_started_at=" . urlencode($call_started_at);
                    if ($session_id > 0) {
                        $common_params .= "&session_id={$session_id}";
                    }
                    ?>
                    <a href="availability-check.php?<?php echo $common_params; ?>&status=picked_up" 
                       class="option-card success">
                        <div class="option-icon">
                            <i class="fas fa-phone-alt"></i>
                        </div>
                        <div class="option-title">Picked Up</div>
                        <div class="option-desc">Donor answered the call</div>
                    </a>
                    
                    <a href="schedule-callback.php?<?php echo $common_params; ?>&status=not_picked_up" 
                       class="option-card danger">
                        <div class="option-icon">
                            <i class="fas fa-phone-slash"></i>
                        </div>
                        <div class="option-title">No Answer</div>
                        <div class="option-desc">Donor didn't pick up</div>
                    </a>
                    
                    <a href="schedule-callback.php?<?php echo $common_params; ?>&status=busy" 
                       class="option-card danger">
                        <div class="option-icon">
                            <i class="fas fa-ban"></i>
                        </div>
                        <div class="option-title">Busy</div>
                        <div class="option-desc">Line was busy</div>
                    </a>
                    
                    <a href="confirm-invalid.php?<?php echo $common_params; ?>&reason=not_working" 
                       class="option-card danger">
                        <div class="option-icon">
                            <i class="fas fa-times-circle"></i>
                        </div>
                        <div class="option-title">Not Working</div>
                        <div class="option-desc">Number not reachable</div>
                    </a>
                </div>
                
                <div class="action-buttons">
                    <a href="make-call.php?donor_id=<?php echo $donor_id; ?>&queue_id=<?php echo $queue_id; ?>" 
                       class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back
                    </a>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
// Twilio Call Status Polling (if session_id exists)
const sessionId = <?php echo $session_id; ?>;
let callStatusInterval = null;

if (sessionId > 0) {
    // Start polling for Twilio call status
    let lastStatus = '';
    
    callStatusInterval = setInterval(() => {
        fetch('api/get-call-status.php?session_id=' + sessionId)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.twilio_status !== lastStatus) {
                    lastStatus = data.twilio_status;
                    handleCallStatusUpdate(data);
                }
            })
            .catch(error => console.error('Status polling error:', error));
    }, 2000); // Poll every 2 seconds
}

function handleCallStatusUpdate(data) {
    const twilioStatus = data.twilio_status;
    
    // ONLY show toast notifications - NO automatic actions
    if (twilioStatus === 'ringing') {
        showToast('Your Phone Ringing', 'info', 'Answer your phone to continue');
    } else if (twilioStatus === 'in-progress' || twilioStatus === 'answered') {
        showToast('You Picked Up', 'success', 'Connecting to donor now');
    } else if (twilioStatus === 'completed') {
        showToast('Donor Answered', 'success', 'Click "Picked Up" when ready to start conversation');
        // Stop polling - call is connected
        if (callStatusInterval) {
            clearInterval(callStatusInterval);
        }
    } else if (twilioStatus === 'failed' || twilioStatus === 'busy' || twilioStatus === 'no-answer' || twilioStatus === 'canceled') {
        // Fetch detailed error info
        fetch('api/get-call-status.php?session_id=' + sessionId)
            .then(response => response.json())
            .then(errorData => {
                let errorMsg = 'Could not connect the call';
                if (errorData.success && errorData.twilio_error_message) {
                    errorMsg = errorData.twilio_error_message;
                }
                showToast('Call Failed', 'error', errorMsg);
            })
            .catch(() => {
                showToast('Call Failed', 'error', 'Could not connect the call');
            });
        
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
    
    const iconClass = type === 'error' ? 'fa-times-circle' : 
                      type === 'success' ? 'fa-check-circle' : 'fa-phone';
    
    container.innerHTML = `
        <div class="toast show ${bgClass} text-white" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="toast-header ${bgClass} text-white border-0">
                <i class="fas ${iconClass} me-2"></i>
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

// Show initial toast if this is a Twilio call
if (sessionId > 0) {
    console.log('Twilio call detected. Session ID:', sessionId);
    showToast('Call in Progress', 'info', 'Listen for your phone ringing');
} else {
    console.log('Manual call mode. No session ID.');
}

// Debug: Log if page is about to redirect
window.addEventListener('beforeunload', (event) => {
    console.warn('Page is being unloaded/redirected!');
});

// Debug: Monitor for any unauthorized redirects
const originalLocationHref = Object.getOwnPropertyDescriptor(window.location, 'href');
console.log('Call status page loaded. Waiting for user action...');
</script>
</body>
</html>

