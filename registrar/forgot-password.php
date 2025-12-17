<?php
/**
 * Forgot Password - Registrar Password Reset
 * 
 * Flow:
 * 1. Enter phone number
 * 2. Receive verification code via WhatsApp/SMS
 * 3. Enter verification code
 * 4. Set new 6-digit passcode
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../shared/csrf.php';

// Start session for storing reset state
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$page_title = 'Reset Password';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <?php include __DIR__ . '/../shared/noindex.php'; ?>
    <title><?php echo $page_title; ?> - Church Fundraising</title>
    <link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/theme.css">
    <link rel="stylesheet" href="assets/auth.css">
    <style>
        /* Mobile-first password reset styles */
        .step-indicator {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-bottom: 2rem;
        }
        
        .step-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #e2e8f0;
            transition: all 0.3s ease;
        }
        
        .step-dot.active {
            background: var(--primary-blue, #0a6286);
            transform: scale(1.2);
        }
        
        .step-dot.completed {
            background: #22c55e;
        }
        
        .step-section {
            display: none;
            animation: fadeIn 0.3s ease;
        }
        
        .step-section.active {
            display: block;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* OTP Input - Mobile optimized */
        .otp-container {
            display: flex;
            gap: 0.5rem;
            justify-content: center;
            margin: 1.5rem 0;
        }
        
        .otp-input {
            width: 48px;
            height: 56px;
            text-align: center;
            font-size: 1.5rem;
            font-weight: 700;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            background: white;
            transition: all 0.2s;
        }
        
        .otp-input:focus {
            border-color: var(--primary-blue, #0a6286);
            box-shadow: 0 0 0 3px rgba(10, 98, 134, 0.15);
            outline: none;
        }
        
        .otp-input.filled {
            border-color: #22c55e;
            background: #f0fdf4;
        }
        
        .otp-input.error {
            border-color: #ef4444;
            background: #fef2f2;
            animation: shake 0.5s ease;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }
        
        /* Passcode Input */
        .passcode-container {
            display: flex;
            gap: 0.5rem;
            justify-content: center;
            margin: 1.5rem 0;
        }
        
        .passcode-input {
            width: 44px;
            height: 52px;
            text-align: center;
            font-size: 1.25rem;
            font-weight: 700;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            background: white;
            transition: all 0.2s;
        }
        
        .passcode-input:focus {
            border-color: var(--primary-blue, #0a6286);
            box-shadow: 0 0 0 3px rgba(10, 98, 134, 0.15);
            outline: none;
        }
        
        /* Info boxes */
        .info-box {
            background: #f0f9ff;
            border-left: 4px solid #0ea5e9;
            border-radius: 8px;
            padding: 1rem;
            margin: 1rem 0;
            font-size: 0.875rem;
        }
        
        .info-box.success {
            background: #f0fdf4;
            border-color: #22c55e;
        }
        
        .info-box.warning {
            background: #fffbeb;
            border-color: #f59e0b;
        }
        
        /* Resend timer */
        .resend-timer {
            text-align: center;
            margin-top: 1rem;
            font-size: 0.875rem;
            color: #64748b;
        }
        
        .resend-timer button {
            background: none;
            border: none;
            color: var(--primary-blue, #0a6286);
            font-weight: 600;
            cursor: pointer;
            text-decoration: underline;
        }
        
        .resend-timer button:disabled {
            color: #94a3b8;
            cursor: not-allowed;
            text-decoration: none;
        }
        
        /* Success animation */
        .success-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #22c55e, #16a34a);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            font-size: 2.5rem;
            color: white;
            animation: successPop 0.5s ease;
        }
        
        @keyframes successPop {
            0% { transform: scale(0); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }
        
        /* Mobile optimizations */
        @media (max-width: 400px) {
            .otp-input {
                width: 42px;
                height: 50px;
                font-size: 1.25rem;
            }
            
            .passcode-input {
                width: 38px;
                height: 46px;
                font-size: 1.1rem;
            }
        }
        
        /* Loading state */
        .btn-loading {
            position: relative;
            color: transparent !important;
        }
        
        .btn-loading::after {
            content: '';
            position: absolute;
            width: 20px;
            height: 20px;
            top: 50%;
            left: 50%;
            margin: -10px 0 0 -10px;
            border: 3px solid rgba(255,255,255,0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* WhatsApp/SMS badges */
        .delivery-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 500;
        }
        
        .delivery-badge.whatsapp {
            background: #dcfce7;
            color: #166534;
        }
        
        .delivery-badge.sms {
            background: #dbeafe;
            color: #1e40af;
        }
    </style>
</head>
<body>
    <div class="auth-wrapper">
        <div class="auth-container">
            <div class="auth-card">
                <div class="auth-header">
                    <div class="auth-logo">
                        <i class="fas fa-key"></i>
                    </div>
                    <h1 class="auth-title">Reset Password</h1>
                    <p class="auth-subtitle">We'll send a verification code to your phone</p>
                </div>
                
                <!-- Step Indicator -->
                <div class="step-indicator">
                    <div class="step-dot active" id="dot-1"></div>
                    <div class="step-dot" id="dot-2"></div>
                    <div class="step-dot" id="dot-3"></div>
                </div>
                
                <!-- Alert Container -->
                <div id="alertContainer"></div>
                
                <!-- Step 1: Enter Phone -->
                <div class="step-section active" id="step1">
                    <form id="phoneForm" onsubmit="sendVerificationCode(event)">
                        <div class="form-floating mb-3">
                            <input type="tel" class="form-control" id="phone" name="phone" 
                                   placeholder="Phone number" required autofocus
                                   pattern="[0-9+\s\-]+" inputmode="tel">
                            <label for="phone">
                                <i class="fas fa-phone me-2"></i>Your Phone Number
                            </label>
                        </div>
                        
                        <div class="info-box">
                            <i class="fab fa-whatsapp text-success me-2"></i>
                            We'll send a 6-digit code to your WhatsApp. If WhatsApp isn't available, we'll send an SMS.
                        </div>
                        
                        <button type="submit" class="btn btn-primary btn-login w-100" id="sendCodeBtn">
                            <i class="fas fa-paper-plane me-2"></i>Send Verification Code
                        </button>
                    </form>
                </div>
                
                <!-- Step 2: Enter Code -->
                <div class="step-section" id="step2">
                    <div class="text-center mb-3">
                        <p class="mb-2">Code sent to:</p>
                        <strong id="maskedPhone"></strong>
                        <div class="mt-2" id="deliveryMethod"></div>
                    </div>
                    
                    <form id="codeForm" onsubmit="verifyCode(event)">
                        <label class="form-label text-center d-block mb-2">Enter 6-digit code</label>
                        <div class="otp-container">
                            <input type="text" class="otp-input" maxlength="1" inputmode="numeric" pattern="[0-9]" required>
                            <input type="text" class="otp-input" maxlength="1" inputmode="numeric" pattern="[0-9]" required>
                            <input type="text" class="otp-input" maxlength="1" inputmode="numeric" pattern="[0-9]" required>
                            <input type="text" class="otp-input" maxlength="1" inputmode="numeric" pattern="[0-9]" required>
                            <input type="text" class="otp-input" maxlength="1" inputmode="numeric" pattern="[0-9]" required>
                            <input type="text" class="otp-input" maxlength="1" inputmode="numeric" pattern="[0-9]" required>
                        </div>
                        
                        <button type="submit" class="btn btn-primary btn-login w-100" id="verifyCodeBtn">
                            <i class="fas fa-check me-2"></i>Verify Code
                        </button>
                    </form>
                    
                    <div class="resend-timer">
                        <span id="timerText">Resend code in <strong id="countdown">60</strong>s</span>
                        <button id="resendBtn" onclick="resendCode()" disabled style="display:none;">
                            Resend Code
                        </button>
                    </div>
                </div>
                
                <!-- Step 3: New Password -->
                <div class="step-section" id="step3">
                    <div class="text-center mb-3">
                        <div class="success-icon" style="width:50px;height:50px;font-size:1.5rem;">
                            <i class="fas fa-check"></i>
                        </div>
                        <p class="text-success fw-bold">Code Verified!</p>
                        <p class="text-muted small">Now create your new 6-digit passcode</p>
                    </div>
                    
                    <form id="passwordForm" onsubmit="setNewPassword(event)">
                        <label class="form-label text-center d-block mb-2">Enter new 6-digit passcode</label>
                        <div class="passcode-container" id="newPasscode">
                            <input type="password" class="passcode-input" maxlength="1" inputmode="numeric" pattern="[0-9]" required>
                            <input type="password" class="passcode-input" maxlength="1" inputmode="numeric" pattern="[0-9]" required>
                            <input type="password" class="passcode-input" maxlength="1" inputmode="numeric" pattern="[0-9]" required>
                            <input type="password" class="passcode-input" maxlength="1" inputmode="numeric" pattern="[0-9]" required>
                            <input type="password" class="passcode-input" maxlength="1" inputmode="numeric" pattern="[0-9]" required>
                            <input type="password" class="passcode-input" maxlength="1" inputmode="numeric" pattern="[0-9]" required>
                        </div>
                        
                        <label class="form-label text-center d-block mb-2 mt-4">Confirm passcode</label>
                        <div class="passcode-container" id="confirmPasscode">
                            <input type="password" class="passcode-input" maxlength="1" inputmode="numeric" pattern="[0-9]" required>
                            <input type="password" class="passcode-input" maxlength="1" inputmode="numeric" pattern="[0-9]" required>
                            <input type="password" class="passcode-input" maxlength="1" inputmode="numeric" pattern="[0-9]" required>
                            <input type="password" class="passcode-input" maxlength="1" inputmode="numeric" pattern="[0-9]" required>
                            <input type="password" class="passcode-input" maxlength="1" inputmode="numeric" pattern="[0-9]" required>
                            <input type="password" class="passcode-input" maxlength="1" inputmode="numeric" pattern="[0-9]" required>
                        </div>
                        
                        <div class="info-box warning mt-3">
                            <i class="fas fa-lightbulb me-2"></i>
                            Choose a passcode you'll remember. This will be your login password.
                        </div>
                        
                        <button type="submit" class="btn btn-primary btn-login w-100" id="setPasswordBtn">
                            <i class="fas fa-save me-2"></i>Save New Password
                        </button>
                    </form>
                </div>
                
                <!-- Step 4: Success -->
                <div class="step-section" id="step4">
                    <div class="text-center">
                        <div class="success-icon">
                            <i class="fas fa-check"></i>
                        </div>
                        <h4 class="text-success mb-3">Password Reset!</h4>
                        <p class="text-muted mb-4">Your password has been successfully changed. You can now login with your new passcode.</p>
                        
                        <a href="login.php" class="btn btn-primary btn-login w-100">
                            <i class="fas fa-sign-in-alt me-2"></i>Login Now
                        </a>
                    </div>
                </div>
                
                <div class="auth-footer">
                    <p class="mb-2">
                        <a href="login.php" class="text-decoration-none">
                            <i class="fas fa-arrow-left me-1"></i>Back to Login
                        </a>
                    </p>
                    <p class="text-muted small mb-0">
                        © <?php echo date('Y'); ?> Church Fundraising
                    </p>
                </div>
            </div>
        </div>
        
        <div class="auth-decoration">
            <div class="circle circle-1"></div>
            <div class="circle circle-2"></div>
            <div class="circle circle-3"></div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // State
        let currentStep = 1;
        let phoneNumber = '';
        let resetToken = '';
        let countdownInterval = null;
        
        // Step navigation
        function goToStep(step) {
            document.querySelectorAll('.step-section').forEach(s => s.classList.remove('active'));
            document.getElementById('step' + step).classList.add('active');
            
            document.querySelectorAll('.step-dot').forEach((dot, i) => {
                dot.classList.remove('active', 'completed');
                if (i + 1 < step) dot.classList.add('completed');
                if (i + 1 === step) dot.classList.add('active');
            });
            
            currentStep = step;
        }
        
        // Show alert
        function showAlert(type, message) {
            const container = document.getElementById('alertContainer');
            container.innerHTML = `
                <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                    <i class="fas fa-${type === 'danger' ? 'exclamation-circle' : 'check-circle'} me-2"></i>
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
        }
        
        // Set button loading
        function setLoading(btnId, loading) {
            const btn = document.getElementById(btnId);
            if (loading) {
                btn.classList.add('btn-loading');
                btn.disabled = true;
            } else {
                btn.classList.remove('btn-loading');
                btn.disabled = false;
            }
        }
        
        // Step 1: Send verification code
        async function sendVerificationCode(e) {
            e.preventDefault();
            
            phoneNumber = document.getElementById('phone').value.trim();
            if (!phoneNumber) {
                showAlert('danger', 'Please enter your phone number');
                return;
            }
            
            setLoading('sendCodeBtn', true);
            
            try {
                const response = await fetch('api/send-reset-code.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ phone: phoneNumber })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    resetToken = result.token;
                    
                    // Mask phone for display
                    const masked = phoneNumber.slice(0, 3) + '****' + phoneNumber.slice(-3);
                    document.getElementById('maskedPhone').textContent = masked;
                    
                    // Show delivery method
                    const method = result.method || 'whatsapp';
                    document.getElementById('deliveryMethod').innerHTML = method === 'whatsapp' 
                        ? '<span class="delivery-badge whatsapp"><i class="fab fa-whatsapp"></i> WhatsApp</span>'
                        : '<span class="delivery-badge sms"><i class="fas fa-sms"></i> SMS</span>';
                    
                    goToStep(2);
                    startCountdown();
                    
                    // Focus first OTP input
                    document.querySelector('.otp-input').focus();
                } else {
                    showAlert('danger', result.error || 'Failed to send code');
                }
            } catch (err) {
                showAlert('danger', 'Connection error. Please try again.');
            }
            
            setLoading('sendCodeBtn', false);
        }
        
        // Countdown timer
        function startCountdown() {
            let seconds = 60;
            document.getElementById('timerText').style.display = 'inline';
            document.getElementById('resendBtn').style.display = 'none';
            
            countdownInterval = setInterval(() => {
                seconds--;
                document.getElementById('countdown').textContent = seconds;
                
                if (seconds <= 0) {
                    clearInterval(countdownInterval);
                    document.getElementById('timerText').style.display = 'none';
                    document.getElementById('resendBtn').style.display = 'inline';
                    document.getElementById('resendBtn').disabled = false;
                }
            }, 1000);
        }
        
        // Resend code
        async function resendCode() {
            document.getElementById('resendBtn').disabled = true;
            
            try {
                const response = await fetch('api/send-reset-code.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ phone: phoneNumber, resend: true })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    resetToken = result.token;
                    showAlert('success', 'New code sent!');
                    startCountdown();
                } else {
                    showAlert('danger', result.error || 'Failed to resend code');
                    document.getElementById('resendBtn').disabled = false;
                }
            } catch (err) {
                showAlert('danger', 'Connection error');
                document.getElementById('resendBtn').disabled = false;
            }
        }
        
        // Step 2: Verify code
        async function verifyCode(e) {
            e.preventDefault();
            
            const otpInputs = document.querySelectorAll('.otp-input');
            let code = '';
            otpInputs.forEach(input => code += input.value);
            
            if (code.length !== 6) {
                otpInputs.forEach(i => i.classList.add('error'));
                setTimeout(() => otpInputs.forEach(i => i.classList.remove('error')), 500);
                showAlert('danger', 'Please enter the complete 6-digit code');
                return;
            }
            
            setLoading('verifyCodeBtn', true);
            
            try {
                const response = await fetch('api/verify-reset-code.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        phone: phoneNumber, 
                        code: code,
                        token: resetToken,
                        action: 'verify'
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    resetToken = result.token; // Updated token for password reset
                    goToStep(3);
                    clearInterval(countdownInterval);
                    
                    // Focus first passcode input
                    document.querySelector('#newPasscode .passcode-input').focus();
                } else {
                    otpInputs.forEach(i => i.classList.add('error'));
                    setTimeout(() => otpInputs.forEach(i => i.classList.remove('error')), 500);
                    showAlert('danger', result.error || 'Invalid code');
                }
            } catch (err) {
                showAlert('danger', 'Connection error');
            }
            
            setLoading('verifyCodeBtn', false);
        }
        
        // Step 3: Set new password
        async function setNewPassword(e) {
            e.preventDefault();
            
            const newInputs = document.querySelectorAll('#newPasscode .passcode-input');
            const confirmInputs = document.querySelectorAll('#confirmPasscode .passcode-input');
            
            let newPass = '';
            let confirmPass = '';
            newInputs.forEach(i => newPass += i.value);
            confirmInputs.forEach(i => confirmPass += i.value);
            
            if (newPass.length !== 6) {
                showAlert('danger', 'Please enter a 6-digit passcode');
                return;
            }
            
            if (newPass !== confirmPass) {
                confirmInputs.forEach(i => i.classList.add('error'));
                setTimeout(() => confirmInputs.forEach(i => i.classList.remove('error')), 500);
                showAlert('danger', 'Passcodes do not match');
                return;
            }
            
            setLoading('setPasswordBtn', true);
            
            try {
                const response = await fetch('api/verify-reset-code.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        phone: phoneNumber, 
                        token: resetToken,
                        password: newPass,
                        action: 'reset'
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    goToStep(4);
                } else {
                    showAlert('danger', result.error || 'Failed to reset password');
                }
            } catch (err) {
                showAlert('danger', 'Connection error');
            }
            
            setLoading('setPasswordBtn', false);
        }
        
        // OTP input handling - auto-focus next
        document.querySelectorAll('.otp-input').forEach((input, index, inputs) => {
            input.addEventListener('input', (e) => {
                const val = e.target.value;
                if (val && index < inputs.length - 1) {
                    inputs[index + 1].focus();
                }
                e.target.classList.toggle('filled', val !== '');
            });
            
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Backspace' && !e.target.value && index > 0) {
                    inputs[index - 1].focus();
                }
            });
            
            // Handle paste
            input.addEventListener('paste', (e) => {
                e.preventDefault();
                const paste = (e.clipboardData || window.clipboardData).getData('text');
                const digits = paste.replace(/\D/g, '').slice(0, 6);
                digits.split('').forEach((digit, i) => {
                    if (inputs[i]) {
                        inputs[i].value = digit;
                        inputs[i].classList.add('filled');
                    }
                });
                if (inputs[digits.length - 1]) inputs[digits.length - 1].focus();
            });
        });
        
        // Passcode input handling
        document.querySelectorAll('.passcode-input').forEach((input, index) => {
            const container = input.closest('.passcode-container');
            const inputs = container.querySelectorAll('.passcode-input');
            
            input.addEventListener('input', (e) => {
                const val = e.target.value;
                const idx = Array.from(inputs).indexOf(input);
                if (val && idx < inputs.length - 1) {
                    inputs[idx + 1].focus();
                }
            });
            
            input.addEventListener('keydown', (e) => {
                const idx = Array.from(inputs).indexOf(input);
                if (e.key === 'Backspace' && !e.target.value && idx > 0) {
                    inputs[idx - 1].focus();
                }
            });
        });
    </script>
</body>
</html>

