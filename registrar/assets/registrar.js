// Registrar Panel JavaScript - Mobile First

// Utility function for phone normalization (used in real-time validation)
function normalizeUkPhone(phone) {
    let digits = phone.replace(/[^0-9+]/g, '');
    if (digits.startsWith('+44')) {
        digits = '0' + digits.slice(3);
    }
    return digits;
}

// DOM ready
document.addEventListener('DOMContentLoaded', function() {
    // Initialize components
    initSidebar();
    initTooltips();
    initFormValidation();
    initRegistrationForm();
});

// Sidebar functionality
function initSidebar() {
    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebarClose = document.getElementById('sidebarClose');
    const desktopSidebarToggle = document.getElementById('desktopSidebarToggle');
    const appContent = document.querySelector('.app-content');

    // If there is no sidebar on this page, bail out early.
    if (!sidebar) {
        return;
    }

    // Toggle sidebar (mobile)
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function() {
            sidebar.classList.add('show');
            if (sidebarOverlay) {
                sidebarOverlay.classList.add('show');
            }
            document.body.style.overflow = 'hidden';
        });
    }

    // Ensure collapsed by default on desktop
    if (sidebar && window.matchMedia('(min-width: 768px)').matches) {
        sidebar.classList.add('collapsed');
        if (appContent) { appContent.classList.add('collapsed'); }
    }

    // Toggle sidebar (desktop)
    if (desktopSidebarToggle) {
        desktopSidebarToggle.addEventListener('click', function() {
            sidebar.classList.toggle('collapsed');
            appContent.classList.toggle('collapsed');
        });
    }
    
    // Close sidebar
    function closeSidebar() {
        sidebar.classList.remove('show');
        if (sidebarOverlay) {
            sidebarOverlay.classList.remove('show');
        }
        document.body.style.overflow = '';
    }
    
    if (sidebarClose) {
        sidebarClose.addEventListener('click', closeSidebar);
    }
    
    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', closeSidebar);
    }
    
    // Close sidebar on escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && sidebar.classList.contains('show')) {
            closeSidebar();
        }
    });
}

function showRepeatDonorModal(donorData) {
    const modal = new bootstrap.Modal(document.getElementById('repeatDonorModal'));
    const donorName = donorData.donor_name || 'Returning Donor';
    const donorPhone = donorData.normalized || 'N/A';
    
    // Populate donor info
    document.getElementById('modalDonorName').textContent = donorName;
    document.getElementById('modalDonorPhone').textContent = donorPhone;
    
    // Populate recent donations table
    const tbody = document.getElementById('donationsTableBody');
    tbody.innerHTML = ''; // Clear existing rows
    
    if (donorData.recent_donations && donorData.recent_donations.length > 0) {
        donorData.recent_donations.forEach(donation => {
            const row = document.createElement('tr');
            const date = new Date(donation.date);
            const formattedDate = date.toLocaleDateString('en-GB', { year: 'numeric', month: 'short', day: 'numeric' });
            const statusBadge = donation.status === 'approved' ? 
                '<span class="badge bg-success">Approved</span>' : 
                '<span class="badge bg-warning">Pending</span>';
            
            row.innerHTML = `
                <td><span class="badge bg-${donation.type === 'pledge' ? 'info' : 'primary'}">${donation.type === 'pledge' ? 'Pledge' : 'Payment'}</span></td>
                <td>£${parseFloat(donation.amount).toFixed(2)}</td>
                <td>${statusBadge}</td>
                <td>${formattedDate}</td>
            `;
            tbody.appendChild(row);
        });
    } else {
        const row = document.createElement('tr');
        row.innerHTML = '<td colspan="4" class="text-center text-muted">No previous donations found</td>';
        tbody.appendChild(row);
    }
    
    modal.show();
}

function initRegistrationForm() {
    const form = document.querySelector('.registration-form');
    if (!form) return;

    // 1. Generate and set a client-side UUID to prevent double submission
    const uuidv4 = () => {
        if (self.crypto && typeof self.crypto.randomUUID === 'function') {
            try { return self.crypto.randomUUID(); } catch (e) {}
        }
        // Fallback UUID v4 generator
        const bytes = new Uint8Array(16);
        if (self.crypto && self.crypto.getRandomValues) {
            self.crypto.getRandomValues(bytes);
        } else {
            for (let i = 0; i < 16; i++) bytes[i] = Math.floor(Math.random() * 256);
        }
        bytes[6] = (bytes[6] & 0x0f) | 0x40; // version 4
        bytes[8] = (bytes[8] & 0x3f) | 0x80; // variant
        const toHex = (n) => n.toString(16).padStart(2, '0');
        const b = Array.from(bytes, toHex);
        return `${b[0]}${b[1]}${b[2]}${b[3]}-${b[4]}${b[5]}-${b[6]}${b[7]}-${b[8]}${b[9]}-${b[10]}${b[11]}${b[12]}${b[13]}${b[14]}${b[15]}`;
    };
    let clientUuid = uuidv4();
    let uuidInput = form.querySelector('input[name="client_uuid"]');
    if (uuidInput) {
        uuidInput.value = clientUuid;
    }

    // 2. Validation + duplicate check + loading state
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
        const additionalDonationCheckbox = document.getElementById('additional_donation');

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

        // If additional_donation checkbox is already checked and visible, skip duplicate check
        const additionalDiv = document.getElementById('additionalDonationDiv');
        if (additionalDiv && !additionalDiv.classList.contains('d-none') && additionalDonationCheckbox && additionalDonationCheckbox.checked) {
            // User already confirmed they want to make another donation, proceed directly
            submitting = true;
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = `<i class="fas fa-spinner fa-spin me-2"></i>Processing...`;
            }
            form.submit();
            return; // Exit early, don't run duplicate check
        }

        // Duplicate check via API (only if we have a phone to check and checkbox is not already approved)
        if (normalized) {
            try {
                const res = await fetch(`../../api/check_donor.php?phone=${encodeURIComponent(normalized)}`);
                const data = await res.json();
                const hasPreviousDonations = (data.pledges?.pending > 0 || data.pledges?.approved > 0 || data.payments?.pending > 0 || data.payments?.approved > 0);
                
                if (hasPreviousDonations) {
                    // Show the repeat donor modal with their history
                    showRepeatDonorModal(data);
                    
                    // Show the additional donation checkbox
                    const additionalDonationDiv = document.getElementById('additionalDonationDiv');
                    if (additionalDonationDiv) {
                        additionalDonationDiv.classList.remove('d-none');
                    }
                    return; // Stop submission
                } else {
                    // No previous donations, hide the checkbox
                    const additionalDonationDiv = document.getElementById('additionalDonationDiv');
                    if (additionalDonationDiv) {
                        additionalDonationDiv.classList.add('d-none');
                        if (additionalDonationCheckbox) additionalDonationCheckbox.checked = false;
                    }
                }
            } catch (err) {
                // If API fails, still proceed; server will enforce duplicate rules
                console.warn('Duplicate check error:', err);
            }
        }

        // Check if additional_donation checkbox is required but not checked
        if (additionalDonationCheckbox && !additionalDonationCheckbox.classList.contains('d-none')) {
            const additionalDonationDiv = document.getElementById('additionalDonationDiv');
            if (!additionalDonationDiv?.classList.contains('d-none') && !additionalDonationCheckbox.checked) {
                alert('Please check the "This donor wants to make another donation" checkbox to continue.');
                return;
            }
        }

        // Passed checks -> submit
        submitting = true;
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = `<i class="fas fa-spinner fa-spin me-2"></i>Processing...`;
        }
        form.submit();
    });

    // 3. Reset form state if user navigates back (e.g., browser back button)
    window.addEventListener('pageshow', function(event) {
        if (event.persisted) {
            const submitBtn = form.querySelector('.btn-submit');
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = `<i class="fas fa-save"></i> Register Donation`;
            }
            // Reset UUID on back navigation to allow a new submission
            clientUuid = uuidv4();
            if (uuidInput) {
                uuidInput.value = clientUuid;
            }
        }
    });
}

// Initialize Bootstrap tooltips
function initTooltips() {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
}

// Form validation
function initFormValidation() {
    const forms = document.querySelectorAll('.needs-validation');
    
    Array.from(forms).forEach(form => {
        form.addEventListener('submit', event => {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            
            form.classList.add('was-validated');
        }, false);
    });
    
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

// Success animation
function showSuccessAnimation(message) {
    const successDiv = document.createElement('div');
    successDiv.className = 'success-animation';
    successDiv.innerHTML = `
        <i class="fas fa-check-circle text-success" style="font-size: 4rem; margin-bottom: 1rem;"></i>
        <h3>${message}</h3>
    `;
    
    document.body.appendChild(successDiv);
    
    setTimeout(() => {
        successDiv.style.opacity = '0';
        setTimeout(() => {
            successDiv.remove();
        }, 300);
    }, 2000);
}

// Format currency
function formatCurrency(amount, currency = 'GBP') {
    return new Intl.NumberFormat('en-GB', {
        style: 'currency',
        currency: currency,
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }).format(amount);
}

// Add ripple effect to buttons
document.addEventListener('click', function(e) {
    const button = e.target.closest('.btn, .quick-amount-btn');
    if (!button) return;
    
    const ripple = document.createElement('span');
    ripple.className = 'ripple';
    button.appendChild(ripple);
    
    const rect = button.getBoundingClientRect();
    const size = Math.max(rect.width, rect.height);
    const x = e.clientX - rect.left - size / 2;
    const y = e.clientY - rect.top - size / 2;
    
    ripple.style.width = ripple.style.height = size + 'px';
    ripple.style.left = x + 'px';
    ripple.style.top = y + 'px';
    
    setTimeout(() => ripple.remove(), 600);
});

// Auto-save form data
function autoSaveForm(formId) {
    const form = document.getElementById(formId);
    if (!form) return;
    
    const saveKey = `registrar_form_${formId}`;
    
    // Load saved data
    const savedData = localStorage.getItem(saveKey);
    if (savedData) {
        try {
            const data = JSON.parse(savedData);
            Object.keys(data).forEach(key => {
                const field = form.elements[key];
                if (field) {
                    if (field.type === 'checkbox' || field.type === 'radio') {
                        field.checked = data[key];
                    } else {
                        field.value = data[key];
                    }
                }
            });
        } catch (e) {
            console.error('Error loading saved form data:', e);
        }
    }
    
    // Save on input
    form.addEventListener('input', function() {
        const data = {};
        Array.from(form.elements).forEach(field => {
            if (field.name && !field.disabled) {
                if (field.type === 'checkbox' || field.type === 'radio') {
                    data[field.name] = field.checked;
                } else {
                    data[field.name] = field.value;
                }
            }
        });
        
        localStorage.setItem(saveKey, JSON.stringify(data));
    });
    
    // Clear on successful submit
    form.addEventListener('submit', function() {
        if (form.checkValidity()) {
            localStorage.removeItem(saveKey);
        }
    });
}

// Phone number formatting
function formatPhoneNumber(input) {
    let value = input.value.replace(/\D/g, '');
    
    if (value.length > 0) {
        if (value.length <= 3) {
            value = value;
        } else if (value.length <= 6) {
            value = value.slice(0, 3) + ' ' + value.slice(3);
        } else if (value.length <= 10) {
            value = value.slice(0, 3) + ' ' + value.slice(3, 6) + ' ' + value.slice(6);
        } else {
            value = value.slice(0, 3) + ' ' + value.slice(3, 6) + ' ' + value.slice(6, 10);
        }
    }
    
    input.value = value;
}

// Export utility functions
window.registrarUtils = {
    showSuccessAnimation,
    formatCurrency,
    autoSaveForm,
    formatPhoneNumber
};

// Load message notifications script
(function() {
    if (!window.location.pathname.includes('/messages/')) {
        const script = document.createElement('script');
        script.src = '../shared/js/message-notifications.js?v=' + Date.now();
        script.async = true;
        document.head.appendChild(script);
    }
})();
