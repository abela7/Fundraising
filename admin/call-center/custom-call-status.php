<?php
/**
 * Custom Call Status Page
 *
 * Tracks call status and allows notes entry during/after custom calls
 */

declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

try {
    $db = db();
    $user_id = (int)$_SESSION['user']['id'];
    $session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;

    if ($session_id <= 0) {
        header('Location: index.php');
        exit;
    }

    // Get call session
    $session_query = $db->prepare("
        SELECT *
        FROM call_center_sessions
        WHERE id = ? AND agent_id = ?
    ");
    $session_query->bind_param('ii', $session_id, $user_id);
    $session_query->execute();
    $session_result = $session_query->get_result();
    $session = $session_result->fetch_assoc();
    $session_query->close();

    if (!$session) {
        header('Location: index.php');
        exit;
    }

    $is_custom_call = $session['is_custom_call'] ?? 0;
    $phone_number = $session['donor_phone_number'] ?? 'Unknown';
    $call_status = $session['twilio_status'] ?? 'pending';
    $conversation_stage = $session['conversation_stage'] ?? 'pending';
    $duration = (int)($session['duration_seconds'] ?? 0);

} catch (Exception $e) {
    error_log("Custom Call Status Error: " . $e->getMessage());
    die("Error: " . htmlspecialchars($e->getMessage()));
}

$page_title = 'Custom Call - Status';
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
        :root {
            --cc-primary: #0a6286;
            --cc-success: #10b981;
            --cc-warning: #f59e0b;
            --cc-danger: #ef4444;
            --cc-border: #e2e8f0;
            --cc-light: #f8fafc;
            --cc-dark: #1e293b;
        }

        .call-status-container {
            max-width: 600px;
            margin: 0 auto;
            padding: 1.5rem;
        }

        .call-header {
            background: linear-gradient(135deg, #0a6286 0%, #0d6efd 100%);
            color: white;
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 12px rgba(10, 98, 134, 0.15);
            text-align: center;
        }

        .call-number {
            font-size: 1.875rem;
            font-weight: 700;
            margin: 0.75rem 0 0 0;
            letter-spacing: 1px;
        }

        .call-status-badge {
            display: inline-block;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.8125rem;
            font-weight: 600;
            margin-top: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .call-status-badge.ringing {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #f59e0b;
        }

        .call-status-badge.connected {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #10b981;
        }

        .call-status-badge.completed {
            background: #dbeafe;
            color: #0c4a6e;
            border: 1px solid #0284c7;
        }

        .call-status-badge.failed {
            background: #fee2e2;
            color: #7f1d1d;
            border: 1px solid #ef4444;
        }

        .call-timer {
            font-size: 2.5rem;
            font-weight: 800;
            margin: 1.5rem 0;
            text-align: center;
            font-family: 'Courier New', monospace;
            color: white;
        }

        .call-info-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            border: 1px solid var(--cc-border);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--cc-border);
        }

        .info-item:last-child {
            border-bottom: none;
        }

        .info-label {
            color: #64748b;
            font-weight: 500;
            font-size: 0.875rem;
        }

        .info-value {
            color: var(--cc-dark);
            font-weight: 600;
        }

        .notes-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            border: 1px solid var(--cc-border);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .form-label {
            font-weight: 600;
            color: var(--cc-dark);
            margin-bottom: 0.5rem;
            font-size: 0.9375rem;
        }

        .form-control {
            border-radius: 10px;
            border: 2px solid var(--cc-border);
            padding: 0.75rem 1rem;
            font-size: 1rem;
            transition: all 0.2s;
        }

        .form-control:focus {
            border-color: var(--cc-primary);
            box-shadow: 0 0 0 3px rgba(10, 98, 134, 0.1);
            outline: none;
        }

        .outcome-buttons {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }

        .outcome-btn {
            padding: 0.75rem;
            font-size: 0.875rem;
            font-weight: 600;
            border-radius: 10px;
            border: 2px solid var(--cc-border);
            background: white;
            color: var(--cc-dark);
            cursor: pointer;
            transition: all 0.2s;
        }

        .outcome-btn:hover {
            border-color: var(--cc-primary);
            background: var(--cc-light);
        }

        .outcome-btn.active {
            background: var(--cc-primary);
            border-color: var(--cc-primary);
            color: white;
        }

        .action-buttons {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.75rem;
        }

        .btn-finish {
            padding: 0.875rem 1.5rem;
            font-size: 1.0625rem;
            font-weight: 700;
            border-radius: 10px;
            transition: all 0.3s;
            border-width: 2px;
        }

        .btn-finish-primary {
            background: var(--cc-success);
            border-color: var(--cc-success);
            color: white;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);
        }

        .btn-finish-primary:hover:not(:disabled) {
            background: #059669;
            border-color: #059669;
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(16, 185, 129, 0.35);
        }

        .btn-finish-secondary {
            background: white;
            border-color: var(--cc-border);
            color: var(--cc-dark);
        }

        .btn-finish-secondary:hover:not(:disabled) {
            background: var(--cc-light);
            border-color: #cbd5e1;
        }

        .spinner-ring {
            display: inline-block;
            position: relative;
            width: 60px;
            height: 60px;
            margin: 1rem auto;
        }

        .spinner-ring div {
            box-sizing: border-box;
            display: block;
            position: absolute;
            width: 60px;
            height: 60px;
            margin: 0;
            border: 4px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            animation: spinner-ring 1.2s cubic-bezier(0.5, 0, 0.5, 1) infinite;
            border-color: white transparent transparent transparent;
        }

        .spinner-ring div:nth-child(1) { animation-delay: -0.45s; }
        .spinner-ring div:nth-child(2) { animation-delay: -0.3s; }
        .spinner-ring div:nth-child(3) { animation-delay: -0.15s; }

        @keyframes spinner-ring {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @media (max-width: 576px) {
            .call-status-container {
                padding: 1rem;
            }

            .call-header {
                padding: 1.25rem;
            }

            .call-number {
                font-size: 1.5rem;
            }

            .call-timer {
                font-size: 2rem;
            }

            .outcome-buttons {
                grid-template-columns: 1fr;
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
            <div class="call-status-container">
                <!-- Call Header -->
                <div class="call-header">
                    <div style="opacity: 0.9;">
                        <i class="fas fa-phone-alt"></i> Custom Call
                    </div>
                    <div class="call-number">
                        <i class="fas fa-phone me-2"></i><?php echo htmlspecialchars($phone_number); ?>
                    </div>

                    <!-- Status Badge -->
                    <div id="statusBadge" class="call-status-badge">
                        <?php
                            $status_class = 'ringing';
                            $status_text = 'Ringing';

                            if ($call_status === 'in-progress' || $conversation_stage === 'contact_made') {
                                $status_class = 'connected';
                                $status_text = 'Connected';
                            } elseif ($call_status === 'completed' || !empty($session['call_ended_at'])) {
                                $status_class = 'completed';
                                $status_text = 'Completed';
                            } elseif ($conversation_stage === 'attempt_failed') {
                                $status_class = 'failed';
                                $status_text = 'Failed';
                            }

                            echo $status_text;
                        ?>
                    </div>

                    <!-- Timer -->
                    <div class="call-timer" id="callTimer">00:00</div>
                </div>

                <!-- Call Info -->
                <div class="call-info-card">
                    <div class="info-item">
                        <span class="info-label">Call Type</span>
                        <span class="info-value">Custom (No Donor Record)</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Status</span>
                        <span class="info-value" id="statusText">
                            <?php
                                echo $call_status === 'completed' ? 'Completed' :
                                     ($call_status === 'in-progress' ? 'In Progress' : 'Ringing');
                            ?>
                        </span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Started</span>
                        <span class="info-value">
                            <?php
                                echo !empty($session['call_started_at']) ?
                                    date('H:i:s', strtotime($session['call_started_at'])) :
                                    'Just now';
                            ?>
                        </span>
                    </div>
                </div>

                <!-- Call Outcome -->
                <div class="notes-card">
                    <form id="callOutcomeForm">
                        <?php require_once __DIR__ . '/../../shared/csrf.php'; echo csrf_input(); ?>

                        <label class="form-label">
                            <i class="fas fa-check-circle me-2"></i>Call Outcome
                        </label>
                        <div class="outcome-buttons">
                            <button type="button" class="outcome-btn" data-outcome="success" onclick="setOutcome('success')">
                                <i class="fas fa-check me-1"></i>Connected
                            </button>
                            <button type="button" class="outcome-btn" data-outcome="no_answer" onclick="setOutcome('no_answer')">
                                <i class="fas fa-phone-slash me-1"></i>No Answer
                            </button>
                            <button type="button" class="outcome-btn" data-outcome="busy" onclick="setOutcome('busy')">
                                <i class="fas fa-hourglass-end me-1"></i>Busy
                            </button>
                            <button type="button" class="outcome-btn" data-outcome="invalid" onclick="setOutcome('invalid')">
                                <i class="fas fa-exclamation-circle me-1"></i>Invalid
                            </button>
                        </div>

                        <input type="hidden" id="outcomeInput" name="outcome" value="">

                        <!-- Notes -->
                        <div class="mb-3">
                            <label for="callNotes" class="form-label">
                                <i class="fas fa-sticky-note me-2"></i>Call Notes
                            </label>
                            <textarea
                                class="form-control"
                                id="callNotes"
                                name="notes"
                                rows="4"
                                placeholder="Add any relevant notes about this call..."
                            ><?php echo htmlspecialchars($session['notes'] ?? ''); ?></textarea>
                            <div class="form-text">Optional - record any details for future reference</div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="action-buttons">
                            <button type="button" class="btn btn-finish btn-finish-primary btn-lg" onclick="saveCallOutcome()">
                                <i class="fas fa-save me-2"></i>Save Call Details
                            </button>
                            <a href="dial-custom.php" class="btn btn-finish btn-finish-secondary btn-lg">
                                <i class="fas fa-phone me-2"></i>Make Another Call
                            </a>
                            <a href="index.php" class="btn btn-finish btn-finish-secondary btn-lg">
                                <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
const SESSION_ID = <?php echo $session_id; ?>;
const INITIAL_CALL_STATUS = '<?php echo htmlspecialchars($call_status); ?>';
const CALL_STARTED_AT = '<?php echo htmlspecialchars($session['call_started_at'] ?? ''); ?>';
let callStartTime = CALL_STARTED_AT ? new Date(CALL_STARTED_AT) : new Date();
let selectedOutcome = '';
let statusCheckInterval = null;

// Start timer
function updateTimer() {
    const now = new Date();
    const elapsed = Math.floor((now - callStartTime) / 1000);
    const minutes = Math.floor(elapsed / 60);
    const seconds = elapsed % 60;

    document.getElementById('callTimer').textContent =
        String(minutes).padStart(2, '0') + ':' +
        String(seconds).padStart(2, '0');
}

// Poll call status
function checkCallStatus() {
    fetch('api/get-call-status.php?session_id=' + SESSION_ID)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.session) {
                const session = data.session;
                const badge = document.getElementById('statusBadge');
                const statusText = document.getElementById('statusText');

                // Update status badge
                badge.className = 'call-status-badge';
                if (session.twilio_status === 'in-progress' || session.conversation_stage === 'contact_made') {
                    badge.classList.add('connected');
                    badge.textContent = 'Connected';
                    statusText.textContent = 'Connected';
                } else if (session.twilio_status === 'completed' || session.call_ended_at) {
                    badge.classList.add('completed');
                    badge.textContent = 'Completed';
                    statusText.textContent = 'Completed';
                    if (statusCheckInterval) {
                        clearInterval(statusCheckInterval);
                    }
                } else if (session.conversation_stage === 'attempt_failed') {
                    badge.classList.add('failed');
                    badge.textContent = 'Failed';
                    statusText.textContent = 'Failed';
                    if (statusCheckInterval) {
                        clearInterval(statusCheckInterval);
                    }
                } else {
                    badge.classList.add('ringing');
                    badge.textContent = 'Ringing';
                }
            }
        })
        .catch(error => {
            console.error('Status check error:', error);
        });
}

function setOutcome(outcome) {
    selectedOutcome = outcome;
    document.getElementById('outcomeInput').value = outcome;

    // Update button states
    document.querySelectorAll('.outcome-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    event.target.closest('.outcome-btn').classList.add('active');
}

function saveCallOutcome() {
    const notes = document.getElementById('callNotes').value.trim();

    const formData = new FormData();
    formData.append('session_id', SESSION_ID);
    formData.append('outcome', selectedOutcome || 'success');
    formData.append('notes', notes);
    formData.append('csrf_token', document.querySelector('input[name="csrf_token"]')?.value || '');

    fetch('api/process-conversation.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('Success', 'success', 'Call details saved');
            setTimeout(() => {
                window.location.href = 'dial-custom.php';
            }, 1500);
        } else {
            showToast('Error', 'error', data.error || 'Failed to save call details');
        }
    })
    .catch(error => {
        console.error('Save error:', error);
        showToast('Error', 'error', 'Failed to save call details');
    });
}

function showToast(title, type, message) {
    const existingContainer = document.getElementById('toastContainer');
    if (existingContainer) {
        existingContainer.remove();
    }

    const container = document.createElement('div');
    container.id = 'toastContainer';
    container.className = 'position-fixed top-0 end-0 p-3';
    container.style.zIndex = '9999';

    const bgClass = type === 'error' ? 'bg-danger' :
                    type === 'success' ? 'bg-success' : 'bg-info';

    const iconClass = type === 'error' ? 'fa-times-circle' :
                      type === 'success' ? 'fa-check-circle' : 'fa-info-circle';

    container.innerHTML = `
        <div class="toast show ${bgClass} text-white" role="alert">
            <div class="toast-header ${bgClass} text-white border-0">
                <i class="fas ${iconClass} me-2"></i>
                <strong class="me-auto">${title}</strong>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
            </div>
            <div class="toast-body" style="font-size: 0.95rem;">
                ${message}
            </div>
        </div>
    `;

    document.body.appendChild(container);

    if (type !== 'error') {
        setTimeout(() => {
            container.remove();
        }, 5000);
    }
}

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    updateTimer();
    setInterval(updateTimer, 1000);

    // Check status every 2 seconds
    checkCallStatus();
    statusCheckInterval = setInterval(checkCallStatus, 2000);
});
</script>
</body>
</html>
