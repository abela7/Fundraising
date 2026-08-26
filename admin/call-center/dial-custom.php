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
    $user_phone_query = "SELECT phone, phone_number FROM users WHERE id = ?";
    $stmt = $db->prepare($user_phone_query);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $user_result = $stmt->get_result();
    $user_data = $user_result->fetch_assoc();
    $stmt->close();

    $user_phone = $user_data['phone_number'] ?? $user_data['phone'] ?? '';

} catch (Exception $e) {
    error_log("Dial Custom Error: " . $e->getMessage());
    die("Error: " . htmlspecialchars($e->getMessage()));
}

$page_title = 'Dial Custom Number';
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
    <style>
        :root {
            --cc-primary: #0a6286;
            --cc-success: #10b981;
            --cc-border: #e2e8f0;
            --cc-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            --cc-shadow-lg: 0 8px 20px rgba(0, 0, 0, 0.12);
            --cc-light: #f8fafc;
            --cc-dark: #1e293b;
        }

        .dial-container {
            max-width: 600px;
            margin: 0 auto;
            padding: 1.5rem;
        }

        .dial-header {
            background: linear-gradient(135deg, #0a6286 0%, #0d6efd 100%);
            color: white;
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 12px rgba(10, 98, 134, 0.15);
            text-align: center;
        }

        .dial-header h1 {
            font-size: 1.75rem;
            font-weight: 700;
            margin: 0;
        }

        .dial-header p {
            font-size: 0.875rem;
            opacity: 0.95;
            margin: 0.5rem 0 0 0;
        }

        .form-card {
            background: white;
            border-radius: 12px;
            box-shadow: var(--cc-shadow);
            border: 1px solid var(--cc-border);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-group:last-child {
            margin-bottom: 0;
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

        .form-text {
            font-size: 0.8125rem;
            color: #64748b;
            margin-top: 0.375rem;
        }

        .input-group-text {
            background: var(--cc-light);
            border: 2px solid var(--cc-border);
            color: var(--cc-dark);
            font-weight: 600;
        }

        .input-group .form-control {
            border-left: none;
        }

        .input-group .form-control:focus ~ .input-group-text {
            border-color: var(--cc-primary);
        }

        .dial-pad {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.625rem;
            margin-bottom: 1rem;
            padding: 1rem;
            background: var(--cc-light);
            border-radius: 10px;
        }

        .dial-btn {
            aspect-ratio: 1;
            border-radius: 8px;
            border: 2px solid var(--cc-border);
            background: white;
            font-weight: 700;
            font-size: 1.25rem;
            cursor: pointer;
            transition: all 0.2s;
            color: var(--cc-dark);
        }

        .dial-btn:hover {
            background: var(--cc-primary);
            color: white;
            border-color: var(--cc-primary);
            transform: scale(1.05);
        }

        .dial-btn:active {
            transform: scale(0.95);
        }

        .info-section {
            background: #dbeafe;
            border-left: 4px solid #0284c7;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            color: #0c4a6e;
        }

        .info-section strong {
            display: block;
            margin-bottom: 0.25rem;
        }

        .action-buttons {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.75rem;
        }

        .btn-dial {
            padding: 0.875rem 1.5rem;
            font-size: 1.0625rem;
            font-weight: 700;
            border-radius: 10px;
            transition: all 0.3s;
            border-width: 2px;
        }

        .btn-dial-primary {
            background: var(--cc-success);
            border-color: var(--cc-success);
            color: white;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);
        }

        .btn-dial-primary:hover:not(:disabled) {
            background: #059669;
            border-color: #059669;
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(16, 185, 129, 0.35);
        }

        .btn-dial-secondary {
            background: white;
            border-color: var(--cc-border);
            color: var(--cc-dark);
        }

        .btn-dial-secondary:hover:not(:disabled) {
            background: var(--cc-light);
            border-color: #cbd5e1;
        }

        .input-display {
            background: var(--cc-light);
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1rem;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--cc-dark);
            text-align: center;
            letter-spacing: 2px;
            min-height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            word-break: break-all;
        }

        .input-display.empty {
            color: #94a3b8;
            font-size: 1rem;
            font-weight: 500;
            letter-spacing: normal;
        }

        .clear-btn {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #64748b;
            cursor: pointer;
            font-size: 1.25rem;
            padding: 0.5rem;
        }

        .clear-btn:hover {
            color: var(--cc-primary);
        }

        .number-input-wrapper {
            position: relative;
        }

        @media (max-width: 576px) {
            .dial-container {
                padding: 1rem;
            }

            .dial-header {
                padding: 1.25rem;
                margin-bottom: 1.5rem;
            }

            .dial-header h1 {
                font-size: 1.5rem;
            }

            .form-card {
                padding: 1.25rem;
            }

            .dial-pad {
                gap: 0.5rem;
                padding: 0.75rem;
            }

            .dial-btn {
                font-size: 1rem;
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
            <div class="dial-container">
                <!-- Header -->
                <div class="dial-header">
                    <h1>
                        <i class="fas fa-phone-volume me-2"></i>Dial Custom Number
                    </h1>
                    <p>Call anyone - no registration needed</p>
                </div>

                <!-- Info Alert -->
                <div class="info-section">
                    <strong><i class="fas fa-info-circle me-2"></i>How it works:</strong>
                    <ol style="margin: 0.5rem 0 0 0; padding-left: 1.5rem; font-size: 0.875rem;">
                        <li>Enter or dial the phone number</li>
                        <li>Click "Call Now" - your phone will ring first</li>
                        <li>Answer and you'll be connected to the recipient</li>
                    </ol>
                </div>

                <!-- Dial Form -->
                <div class="form-card">
                    <form id="customDialForm">
                        <?php require_once __DIR__ . '/../../shared/csrf.php'; echo csrf_input(); ?>

                        <!-- Phone Number Input -->
                        <div class="form-group">
                            <label for="customPhoneInput" class="form-label">
                                <i class="fas fa-phone me-2"></i>Phone Number
                            </label>
                            <div class="input-group number-input-wrapper">
                                <span class="input-group-text">+44</span>
                                <input
                                    type="tel"
                                    class="form-control form-control-lg"
                                    id="customPhoneInput"
                                    name="custom_phone"
                                    placeholder="7XXXXXXXXX"
                                    autocomplete="off"
                                    maxlength="20"
                                    required
                                >
                                <button type="button" class="clear-btn" onclick="clearPhoneInput()" title="Clear">
                                    <i class="fas fa-times-circle"></i>
                                </button>
                            </div>
                            <div class="form-text">
                                <i class="fas fa-lock me-1"></i>UK mobile format: 07XXXXXXXXX (we'll add country code)
                            </div>
                        </div>

                        <!-- Dial Pad -->
                        <div class="form-group">
                            <label class="form-label">Quick Dial Pad</label>
                            <div class="dial-pad" id="dialPad">
                                <button type="button" class="dial-btn" data-digit="1">1</button>
                                <button type="button" class="dial-btn" data-digit="2">2</button>
                                <button type="button" class="dial-btn" data-digit="3">3</button>
                                <button type="button" class="dial-btn" data-digit="4">4</button>
                                <button type="button" class="dial-btn" data-digit="5">5</button>
                                <button type="button" class="dial-btn" data-digit="6">6</button>
                                <button type="button" class="dial-btn" data-digit="7">7</button>
                                <button type="button" class="dial-btn" data-digit="8">8</button>
                                <button type="button" class="dial-btn" data-digit="9">9</button>
                                <button type="button" class="dial-btn" data-digit="*">*</button>
                                <button type="button" class="dial-btn" data-digit="0">0</button>
                                <button type="button" class="dial-btn" data-digit="#">#</button>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="action-buttons mt-4">
                            <?php if (!empty($user_phone)): ?>
                            <button type="button" class="btn btn-dial btn-dial-primary btn-lg" id="dialBtn" onclick="initiateCustomCall()">
                                <i class="fas fa-phone-volume me-2"></i>Call Now
                                <small class="d-block" style="font-size: 0.7rem; opacity: 0.9; font-weight: 400;">Your phone (<?php echo htmlspecialchars($user_phone); ?>) will ring first</small>
                            </button>
                            <?php else: ?>
                            <button type="button" class="btn btn-dial btn-dial-primary btn-lg" onclick="showAgentPhoneModal()">
                                <i class="fas fa-phone-volume me-2"></i>Call Now
                                <small class="d-block" style="font-size: 0.7rem; opacity: 0.9; font-weight: 400;">Enter your phone number</small>
                            </button>
                            <?php endif; ?>

                            <a href="index.php" class="btn btn-dial btn-dial-secondary btn-lg">
                                <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Agent Phone Modal -->
<div class="modal fade" id="agentPhoneModal" tabindex="-1" aria-labelledby="agentPhoneModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="agentPhoneModalLabel">
                    <i class="fas fa-phone me-2"></i>Your Phone Number
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="agentPhoneForm">
                    <div class="mb-3">
                        <label for="agentPhoneInput" class="form-label fw-semibold">
                            Your Phone Number <span class="text-danger">*</span>
                        </label>
                        <input
                            type="tel"
                            class="form-control form-control-lg"
                            id="agentPhoneInput"
                            name="agent_phone"
                            placeholder="07XXXXXXXXX or +447XXXXXXXXX"
                            required
                            pattern="^(07[0-9]{9}|447[0-9]{9}|\+447[0-9]{9})$"
                        >
                        <div class="form-text">
                            <i class="fas fa-lock me-1"></i>UK mobile format (e.g., 07123456789)
                        </div>
                    </div>

                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Important:</strong> Make sure your phone is nearby and volume is on!
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary btn-lg" onclick="confirmCustomCall()">
                    <i class="fas fa-phone-volume me-2"></i>Call Now
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
let agentPhoneModal = null;

// Initialize dial pad
document.addEventListener('DOMContentLoaded', function() {
    const dialPad = document.getElementById('dialPad');
    const phoneInput = document.getElementById('customPhoneInput');

    dialPad.addEventListener('click', function(e) {
        if (e.target.classList.contains('dial-btn')) {
            e.preventDefault();
            const digit = e.target.dataset.digit;
            phoneInput.value += digit;
            phoneInput.focus();
        }
    });

    // Sanitize input - only allow digits, spaces, +, -, ()
    phoneInput.addEventListener('input', function() {
        this.value = this.value.replace(/[^0-9\s\+\-()]/g, '');
    });
});

function clearPhoneInput() {
    document.getElementById('customPhoneInput').value = '';
    document.getElementById('customPhoneInput').focus();
}

function showAgentPhoneModal() {
    if (!agentPhoneModal) {
        agentPhoneModal = new bootstrap.Modal(document.getElementById('agentPhoneModal'));
    }
    agentPhoneModal.show();
}

function confirmCustomCall() {
    const agentPhone = document.getElementById('agentPhoneInput').value.trim();

    if (!agentPhone) {
        showToast('Error', 'error', 'Please enter your phone number');
        return;
    }

    // Store phone in form and close modal
    const form = document.getElementById('customDialForm');
    form.innerHTML += '<input type="hidden" name="agent_phone" value="' + htmlEscape(agentPhone) + '">';

    agentPhoneModal.hide();
    initiateCustomCall();
}

function initiateCustomCall() {
    const customPhone = document.getElementById('customPhoneInput').value.trim();
    const agentPhone = document.querySelector('input[name="agent_phone"]')?.value || '<?php echo htmlspecialchars($user_phone); ?>';

    if (!customPhone) {
        showToast('Error', 'error', 'Please enter a phone number to call');
        return;
    }

    if (!agentPhone) {
        showToast('Error', 'error', 'Agent phone number is missing. Please try again.');
        return;
    }

    const btn = document.getElementById('dialBtn') || event.target;
    const originalHTML = btn.innerHTML;

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Initiating Call...';

    showToast('Initiating Call', 'info', 'Preparing to call your phone...');

    const formData = new FormData();
    formData.append('custom_phone', customPhone);
    formData.append('agent_phone', agentPhone);
    formData.append('csrf_token', document.querySelector('input[name="csrf_token"]')?.value || '');

    fetch('api/custom-call-initiate.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('Call Started', 'success', 'Your phone will ring shortly');
            setTimeout(() => {
                window.location.href = 'custom-call-status.php?session_id=' + data.session_id;
            }, 1000);
        } else {
            throw new Error(data.error || 'Failed to initiate call');
        }
    })
    .catch(error => {
        console.error('Call error:', error);
        showToast('Call Failed', 'error', error.message);
        btn.disabled = false;
        btn.innerHTML = originalHTML;
    });
}

function htmlEscape(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
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
                      type === 'success' ? 'fa-check-circle' : 'fa-phone';

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
</script>
</body>
</html>
