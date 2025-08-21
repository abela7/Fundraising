/**
 * Public Donation Page JavaScript
 * Handles form interactions, validation, and user experience
 */

document.addEventListener('DOMContentLoaded', function() {
    initDonationForm();
});

function initDonationForm() {
    const form = document.querySelector('.donation-form');
    if (!form) return;

    // 1. Generate and set a client-side UUID to prevent double submission
    let clientUuid = generateUUID();
    let uuidInput = form.querySelector('input[name="client_uuid"]');
    if (uuidInput) {
        uuidInput.value = clientUuid;
    }

    // 2. Setup form interactions
    setupAmountSelection();
    setupPaymentTypeToggle();
    setupAnonymousToggle();
    setupFormValidation();
    setupSubmissionHandler();
}

function setupAmountSelection() {
    // Amount option selection
    document.querySelectorAll('.amount-option').forEach(option => {
        option.addEventListener('click', function() {
            // Remove active class from all options
            document.querySelectorAll('.amount-option').forEach(opt => {
                opt.classList.remove('active');
                opt.querySelector('input').checked = false;
            });
            
            // Add active class to clicked option
            this.classList.add('active');
            this.querySelector('input').checked = true;
            
            // Handle custom amount visibility
            const pack = this.querySelector('input').value;
            const customAmountDiv = document.getElementById('customAmountDiv');
            
            if (pack === 'custom') {
                customAmountDiv.classList.remove('d-none');
                document.getElementById('custom_amount').focus();
            } else {
                customAmountDiv.classList.add('d-none');
                document.getElementById('custom_amount').value = '';
            }
        });
    });

    // Custom amount input handling
    const customAmountInput = document.getElementById('custom_amount');
    if (customAmountInput) {
        customAmountInput.addEventListener('input', function() {
            // Format the input value
            let value = parseFloat(this.value);
            if (!isNaN(value) && value > 0) {
                this.classList.remove('is-invalid');
                this.classList.add('is-valid');
            } else {
                this.classList.remove('is-valid');
                if (this.value.trim() !== '') {
                    this.classList.add('is-invalid');
                }
            }
        });
    }
}

function setupPaymentTypeToggle() {
    // Payment type selection
    document.querySelectorAll('.payment-type-option').forEach(option => {
        option.addEventListener('click', function() {
            // Remove active class from all options
            document.querySelectorAll('.payment-type-option').forEach(opt => {
                opt.classList.remove('active');
                opt.querySelector('input').checked = false;
            });
            
            // Add active class to clicked option
            this.classList.add('active');
            this.querySelector('input').checked = true;
            
            // Handle payment method visibility
            const type = this.querySelector('input').value;
            const paymentMethodDiv = document.getElementById('paymentMethodDiv');
            const paymentMethodSelect = document.getElementById('payment_method');
            
            if (type === 'paid') {
                paymentMethodDiv.classList.remove('d-none');
                paymentMethodSelect.required = true;
            } else {
                paymentMethodDiv.classList.add('d-none');
                paymentMethodSelect.required = false;
                paymentMethodSelect.value = '';
            }
        });
    });
}

function setupAnonymousToggle() {
    const anonymousCheckbox = document.getElementById('anonymous');
    const personalInfoDiv = document.getElementById('personalInfo');
    const nameField = document.getElementById('name');
    const phoneField = document.getElementById('phone');
    const emailField = document.getElementById('email');

    if (anonymousCheckbox) {
        anonymousCheckbox.addEventListener('change', function() {
            if (this.checked) {
                // Make anonymous
                personalInfoDiv.style.opacity = '0.5';
                nameField.required = false;
                phoneField.required = false;
                nameField.value = '';
                phoneField.value = '';
                emailField.value = '';
                nameField.placeholder = 'Not needed for anonymous donations';
                phoneField.placeholder = 'Not needed for anonymous donations';
                
                // Clear validation states
                [nameField, phoneField, emailField].forEach(field => {
                    field.classList.remove('is-invalid', 'is-valid');
                });
            } else {
                // Make public
                personalInfoDiv.style.opacity = '1';
                nameField.required = true;
                phoneField.required = true;
                nameField.placeholder = 'Enter your full name';
                phoneField.placeholder = '07XXXXXXXXX';
            }
        });
    }
}

function setupFormValidation() {
    // Real-time validation for phone number
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
    const form = document.querySelector('.donation-form');
    if (!form) return;

    let submitting = false;

    form.addEventListener('submit', async function(e) {
        if (submitting) {
            e.preventDefault();
            return;
        }

        // Validate form before submission
        if (!validateForm()) {
            e.preventDefault();
            return;
        }

        // Check for duplicates if we have a phone number
        const phoneField = document.getElementById('phone');
        const anonymousCheckbox = document.getElementById('anonymous');
        
        if (phoneField && !anonymousCheckbox.checked && phoneField.value.trim()) {
            e.preventDefault();
            
            const normalizedPhone = normalizeUkPhone(phoneField.value);
            
            try {
                const isDuplicate = await checkForDuplicate(normalizedPhone);
                if (isDuplicate) {
                    showAlert('This phone number already has a registered pledge or payment. Please contact support if you need to make additional donations.', 'danger');
                    return;
                }
            } catch (error) {
                console.warn('Duplicate check failed:', error);
                // Continue with submission if check fails
            }
        }

        // Set loading state
        submitting = true;
        const submitBtn = form.querySelector('button[type="submit"]');
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

        // Allow form to submit naturally
    });
}

function validateForm() {
    let isValid = true;
    const errors = [];

    // Check if amount is selected
    const amountSelected = document.querySelector('.amount-option input:checked');
    if (!amountSelected) {
        errors.push('Please select a donation amount.');
        isValid = false;
    } else if (amountSelected.value === 'custom') {
        const customAmount = document.getElementById('custom_amount');
        if (!customAmount.value || parseFloat(customAmount.value) <= 0) {
            errors.push('Please enter a valid custom amount.');
            customAmount.classList.add('is-invalid');
            isValid = false;
        }
    }

    // Check if payment type is selected
    const paymentTypeSelected = document.querySelector('.payment-type-option input:checked');
    if (!paymentTypeSelected) {
        errors.push('Please select a payment type.');
        isValid = false;
    } else if (paymentTypeSelected.value === 'paid') {
        const paymentMethod = document.getElementById('payment_method');
        if (!paymentMethod.value) {
            errors.push('Please select how you made the payment.');
            paymentMethod.classList.add('is-invalid');
            isValid = false;
        }
    }

    // Check personal information for non-anonymous donations
    const anonymousCheckbox = document.getElementById('anonymous');
    if (!anonymousCheckbox.checked) {
        const nameField = document.getElementById('name');
        const phoneField = document.getElementById('phone');

        if (!nameField.value.trim()) {
            errors.push('Name is required for non-anonymous donations.');
            nameField.classList.add('is-invalid');
            isValid = false;
        }

        if (!phoneField.value.trim()) {
            errors.push('Phone number is required for non-anonymous donations.');
            phoneField.classList.add('is-invalid');
            isValid = false;
        } else {
            const phone = normalizeUkPhone(phoneField.value);
            if (!/^07\d{9}$/.test(phone)) {
                errors.push('Please enter a valid UK mobile number starting with 07.');
                phoneField.classList.add('is-invalid');
                isValid = false;
            }
        }

        // Validate email if provided
        const emailField = document.getElementById('email');
        if (emailField.value.trim() && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailField.value)) {
            errors.push('Please enter a valid email address.');
            emailField.classList.add('is-invalid');
            isValid = false;
        }
    }

    if (!isValid) {
        showAlert(errors.join(' '), 'danger');
        // Scroll to first error
        const firstInvalid = document.querySelector('.is-invalid');
        if (firstInvalid) {
            firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
            firstInvalid.focus();
        }
    }

    return isValid;
}

async function checkForDuplicate(phone) {
    try {
        const response = await fetch('/api/check_donor.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ phone: phone })
        });
        
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        
        const data = await response.json();
        return data.exists || false;
    } catch (error) {
        console.error('Error checking for duplicate:', error);
        return false; // Don't block submission if check fails
    }
}

function showAlert(message, type = 'info') {
    // Remove existing alerts
    const existingAlerts = document.querySelectorAll('.alert');
    existingAlerts.forEach(alert => alert.remove());

    // Create new alert
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
    alertDiv.innerHTML = `
        <i class="fas fa-${type === 'danger' ? 'exclamation-triangle' : 'info-circle'} me-2"></i>
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;

    // Insert before form
    const form = document.querySelector('.donation-form');
    form.parentNode.insertBefore(alertDiv, form);

    // Scroll to alert
    alertDiv.scrollIntoView({ behavior: 'smooth', block: 'center' });

    // Auto-dismiss after 10 seconds
    setTimeout(() => {
        if (alertDiv.parentNode) {
            alertDiv.remove();
        }
    }, 10000);
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

// Utility functions for enhanced UX
function animateSuccess() {
    const successAlert = document.querySelector('.alert-success');
    if (successAlert) {
        successAlert.style.animation = 'none';
        setTimeout(() => {
            successAlert.style.animation = 'fadeInUp 0.5s ease-out';
        }, 10);
    }
}

// Initialize success animation if page loads with success message
document.addEventListener('DOMContentLoaded', function() {
    animateSuccess();
});

// Keyboard accessibility
document.addEventListener('keydown', function(e) {
    // Allow Enter/Space to select amount and payment options
    if (e.key === 'Enter' || e.key === ' ') {
        const target = e.target;
        if (target.classList.contains('amount-option') || target.classList.contains('payment-type-option')) {
            e.preventDefault();
            target.click();
        }
    }
});

// Add ARIA labels and improved accessibility
document.addEventListener('DOMContentLoaded', function() {
    // Add aria-labels to amount options
    document.querySelectorAll('.amount-option').forEach((option, index) => {
        option.setAttribute('tabindex', '0');
        option.setAttribute('role', 'radio');
        option.setAttribute('aria-describedby', `amount-description-${index}`);
    });

    // Add aria-labels to payment type options
    document.querySelectorAll('.payment-type-option').forEach((option, index) => {
        option.setAttribute('tabindex', '0');
        option.setAttribute('role', 'radio');
        option.setAttribute('aria-describedby', `payment-description-${index}`);
    });
});
