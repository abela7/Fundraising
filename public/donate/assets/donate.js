/**
 * Public Donation Page JavaScript - Registrar Style Copy
 * Handles form interactions exactly like the registrar interface
 */

document.addEventListener('DOMContentLoaded', function() {
    initRegistrationForm();
});

function initRegistrationForm() {
    const form = document.querySelector('.registration-form');
    if (!form) return;

    // 1. Generate and set a client-side UUID to prevent double submission
    let clientUuid = generateUUID();
    let uuidInput = form.querySelector('input[name="client_uuid"]');
    if (uuidInput) {
        uuidInput.value = clientUuid;
    }

    // 2. Setup registrar-style interactions
    setupQuickAmountSelection();
    setupPaymentTypeToggle();
    setupAnonymousToggle();
    setupFormValidation();
    setupSubmissionHandler();
}

// Quick amount selection - exactly like registrar
function setupQuickAmountSelection() {
    document.querySelectorAll('.quick-amount-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.quick-amount-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            
            const pack = this.dataset.pack;
            if (pack === 'custom') {
                document.getElementById('customAmountDiv').classList.remove('d-none');
                document.getElementById('custom_amount').focus();
            } else {
                document.getElementById('customAmountDiv').classList.add('d-none');
            }
        });
    });
}

// Payment type toggle - exactly like registrar
function setupPaymentTypeToggle() {
    document.querySelectorAll('input[name="type"]').forEach(input => {
        input.addEventListener('change', function() {
            if (this.value === 'paid') {
                document.getElementById('paymentMethodDiv').classList.remove('d-none');
                document.getElementById('payment_method').required = true;
            } else {
                document.getElementById('paymentMethodDiv').classList.add('d-none');
                document.getElementById('payment_method').required = false;
            }
        });
    });
}

// Anonymous toggle - exactly like registrar
function setupAnonymousToggle() {
    const anonymousCheckbox = document.getElementById('anonymous');
    const nameField = document.getElementById('name');
    const phoneField = document.getElementById('phone');
    
    if (anonymousCheckbox) {
        anonymousCheckbox.addEventListener('change', function() {
            if (this.checked) {
                nameField.required = false;
                phoneField.required = false;
                nameField.placeholder = 'Anonymous';
                phoneField.placeholder = 'Anonymous';
            } else {
                nameField.required = true;
                phoneField.required = true;
                nameField.placeholder = 'Enter full name';
                phoneField.placeholder = 'Enter phone number';
            }
        });
    }
}

function setupFormValidation() {
    // Real-time validation for phone number (UK mobile format)
    const phoneField = document.getElementById('phone');
    if (phoneField) {
        phoneField.addEventListener('input', function() {
            const phone = normalizeUkPhone(this.value);
            const isValid = /^07\d{9}$/.test(phone);
            const isRequired = this.required;
            
            if (isRequired && this.value.trim() === '') {
                this.classList.remove('is-valid', 'is-invalid');
            } else if (this.value.trim() !== '') {
                if (isValid) {
                    this.classList.remove('is-invalid');
                    this.classList.add('is-valid');
                } else {
                    this.classList.remove('is-valid');
                    this.classList.add('is-invalid');
                }
            }
        });
    }

    // Email validation
    const emailField = document.getElementById('email');
    if (emailField) {
        emailField.addEventListener('input', function() {
            if (this.value.trim() === '') {
                this.classList.remove('is-valid', 'is-invalid');
            } else {
                const isValid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(this.value);
                if (isValid) {
                    this.classList.remove('is-invalid');
                    this.classList.add('is-valid');
                } else {
                    this.classList.remove('is-valid');
                    this.classList.add('is-invalid');
                }
            }
        });
    }

    // Name validation
    const nameField = document.getElementById('name');
    if (nameField) {
        nameField.addEventListener('input', function() {
            const isRequired = this.required;
            if (isRequired && this.value.trim() === '') {
                this.classList.remove('is-valid');
                this.classList.add('is-invalid');
            } else if (this.value.trim().length >= 2) {
                this.classList.remove('is-invalid');
                this.classList.add('is-valid');
            } else if (this.value.trim() !== '') {
                this.classList.remove('is-valid');
                this.classList.add('is-invalid');
            } else {
                this.classList.remove('is-valid', 'is-invalid');
            }
        });
    }
}

function setupSubmissionHandler() {
    const form = document.querySelector('.registration-form');
    if (!form) return;

    let submitting = false;

    form.addEventListener('submit', async function(e) {
        if (submitting) return; // guard double click
        e.preventDefault();
        e.stopPropagation();

        const submitBtn = form.querySelector('.btn-submit');
        const phoneEl = document.getElementById('phone');
        const anonEl = document.getElementById('anonymous');
        const typeEl = form.querySelector('input[name="type"]:checked');
        const typeVal = typeEl ? typeEl.value : 'pledge';
        const rawPhone = (phoneEl?.value || '').trim();
        const isAnon = !!(anonEl && anonEl.checked);

        const normalizeUk = (v) => {
            let s = (v||'').replace(/[^0-9+]/g, '');
            if (s.startsWith('+44')) s = '0' + s.slice(3);
            return s;
        };

        // Phone format validation when required
        let normalized = rawPhone;
        if (typeVal === 'pledge' || (typeVal === 'paid' && !isAnon)) {
            normalized = normalizeUk(rawPhone);
            if (!/^07\d{9}$/.test(normalized)) {
                alert('Please enter a valid UK mobile number starting with 07.');
                return;
            }
        }

        // Duplicate check via API (only if we have a phone to check)
        if (normalized && /^07\d{9}$/.test(normalized)) {
            try {
                const resp = await fetch('/api/check_donor.php?phone=' + encodeURIComponent(normalized));
                if (resp.ok) {
                    const data = await resp.json();
                    if (data.exists) {
                        alert('This phone number already has a registered pledge or payment. Please contact support if you need to make additional donations.');
                        return;
                    }
                } else {
                    console.warn('Duplicate check failed, continuing...');
                }
            } catch (err) {
                console.warn('Duplicate check error:', err);
                // Continue with submission if check fails
            }
        }

        // Set loading state
        submitting = true;
        const originalHTML = submitBtn.innerHTML;
        
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Processing...';

        // Generate new UUID for next submission
        setTimeout(() => {
            const uuidInput = form.querySelector('input[name="client_uuid"]');
            if (uuidInput) {
                uuidInput.value = generateUUID();
            }
            submitting = false;
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalHTML;
        }, 2000);

        // Submit the form naturally
        form.submit();
    });
}

function normalizeUkPhone(phone) {
    let digits = phone.replace(/[^0-9+]/g, '');
    if (digits.startsWith('+44')) {
        digits = '0' + digits.slice(3);
    }
    return digits;
}

function generateUUID() {
    // Simple UUID v4 generator
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
        const r = Math.random() * 16 | 0;
        const v = c == 'x' ? r : (r & 0x3 | 0x8);
        return v.toString(16);
    });
}

// Utility functions from registrar
function initSidebar() {
    // Not needed for public page
}

// Initialize on load (like registrar)
document.addEventListener('DOMContentLoaded', function() {
    // Auto-focus first input
    const firstInput = document.querySelector('.registration-form input:not([type="hidden"]):not([type="radio"]):not([type="checkbox"])');
    if (firstInput) {
        setTimeout(() => firstInput.focus(), 100);
    }

    // Initialize all form interactions
    initRegistrationForm();
});

// Auto-dismiss alerts after 10 seconds (like registrar)
document.addEventListener('DOMContentLoaded', function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            if (alert.parentNode) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }
        }, 10000);
    });
});