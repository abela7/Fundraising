<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

try {
    $db = db();

    // Get agents (admin/registrar) with a phone number on file
    $agents = [];
    $agents_result = $db->query("
        SELECT id, name, phone, phone_number
        FROM users
        WHERE role IN ('admin', 'registrar') AND active = 1
        ORDER BY name
    ");
    if ($agents_result) {
        while ($row = $agents_result->fetch_assoc()) {
            $row['display_phone'] = $row['phone_number'] ?: $row['phone'];
            $agents[] = $row;
        }
    }

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
            --cc-light: #f8fafc;
            --cc-dark: #1e293b;
        }

        .dial-container {
            max-width: 560px;
            margin: 0 auto;
            padding: 1.5rem;
        }

        .dial-header {
            background: linear-gradient(135deg, #0a6286 0%, #0d6efd 100%);
            color: white;
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 12px rgba(10, 98, 134, 0.15);
            text-align: center;
        }

        .dial-header h1 {
            font-size: 1.5rem;
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

        .form-select,
        .form-control {
            border-radius: 10px;
            border: 2px solid var(--cc-border);
            padding: 0.75rem 1rem;
            font-size: 1rem;
        }

        .form-select:focus,
        .form-control:focus {
            border-color: var(--cc-primary);
            box-shadow: 0 0 0 3px rgba(10, 98, 134, 0.1);
        }

        .form-text {
            font-size: 0.8125rem;
            color: #64748b;
            margin-top: 0.375rem;
        }

        .step-number {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: var(--cc-primary);
            color: white;
            font-size: 0.75rem;
            font-weight: 700;
            margin-right: 0.5rem;
        }

        .dial-pad {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.625rem;
            margin-top: 0.75rem;
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
        }

        .btn-dial-primary {
            background: var(--cc-success);
            border-color: var(--cc-success);
            color: white;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);
            padding: 0.875rem 1.5rem;
            font-size: 1.0625rem;
            font-weight: 700;
            border-radius: 10px;
            width: 100%;
        }

        .btn-dial-primary:hover:not(:disabled) {
            background: #059669;
            border-color: #059669;
        }

        .btn-dial-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
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
                <div class="dial-header">
                    <h1><i class="fas fa-phone-volume me-2"></i>Dial Custom Number</h1>
                    <p>Call anyone - no registration needed</p>
                </div>

                <div class="form-card">
                    <form id="customDialForm">
                        <?php require_once __DIR__ . '/../../shared/csrf.php'; echo csrf_input(); ?>

                        <!-- Step 1: Choose Agent -->
                        <div class="form-group">
                            <label for="agentSelect" class="form-label">
                                <span class="step-number">1</span>Choose Agent
                            </label>
                            <select class="form-select form-select-lg" id="agentSelect" required>
                                <option value="">-- Select agent --</option>
                                <?php foreach ($agents as $agent): ?>
                                    <?php if (!empty($agent['display_phone'])): ?>
                                    <option value="<?php echo htmlspecialchars($agent['display_phone']); ?>">
                                        <?php echo htmlspecialchars($agent['name']); ?> (<?php echo htmlspecialchars($agent['display_phone']); ?>)
                                    </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">This agent's phone will ring first.</div>
                        </div>

                        <!-- Step 2: Number to Call -->
                        <div class="form-group">
                            <label for="customPhoneInput" class="form-label">
                                <span class="step-number">2</span>Number to Call
                            </label>
                            <input
                                type="tel"
                                class="form-control form-control-lg"
                                id="customPhoneInput"
                                placeholder="07XXXXXXXXX or +447XXXXXXXXX"
                                autocomplete="off"
                                maxlength="20"
                                required
                            >
                            <div class="form-text">UK format accepted, e.g. 07123456789</div>

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

                        <!-- Step 3: Call -->
                        <div class="form-group mt-4">
                            <button type="button" class="btn-dial-primary" id="dialBtn" onclick="initiateCustomCall()">
                                <i class="fas fa-phone-volume me-2"></i>Call
                            </button>
                        </div>
                    </form>
                </div>

                <a href="index.php" class="btn btn-outline-secondary w-100">
                    <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                </a>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const dialPad = document.getElementById('dialPad');
    const phoneInput = document.getElementById('customPhoneInput');

    dialPad.addEventListener('click', function(e) {
        if (e.target.classList.contains('dial-btn')) {
            e.preventDefault();
            phoneInput.value += e.target.dataset.digit;
            phoneInput.focus();
        }
    });

    phoneInput.addEventListener('input', function() {
        this.value = this.value.replace(/[^0-9\s\+\-()]/g, '');
    });
});

function initiateCustomCall() {
    const agentPhone = document.getElementById('agentSelect').value;
    const customPhone = document.getElementById('customPhoneInput').value.trim();

    if (!agentPhone) {
        showToast('Error', 'error', 'Please choose an agent');
        return;
    }

    if (!customPhone) {
        showToast('Error', 'error', 'Please enter a phone number to call');
        return;
    }

    const btn = document.getElementById('dialBtn');
    const originalHTML = btn.innerHTML;

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Calling...';

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
            showToast('Call Started', 'success', 'The agent phone will ring shortly');
            btn.disabled = false;
            btn.innerHTML = originalHTML;
            document.getElementById('customPhoneInput').value = '';
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
