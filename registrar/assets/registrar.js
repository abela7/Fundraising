// Registrar Panel JavaScript - Mobile First

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

    // Toggle sidebar (mobile)
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function() {
            sidebar.classList.add('show');
            sidebarOverlay.classList.add('show');
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
        sidebarOverlay.classList.remove('show');
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

function initRegistrationForm() {
    const form = document.querySelector('.registration-form');
    if (!form) return;

    // 1. Generate and set a client-side UUID to prevent double submission
    let clientUuid = self.crypto.randomUUID();
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
        if (normalized) {
            try {
                const res = await fetch(`/fundraising/api/check_donor.php?phone=${encodeURIComponent(normalized)}`);
                const data = await res.json();
                if (data && (data.pledges?.pending > 0 || data.pledges?.approved > 0)) {
                    alert('This donor already has a registered pledge. Please review existing records instead of creating duplicates.');
                    return;
                }
            } catch (err) {
                // If API fails, still proceed; server will enforce duplicate rules
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
            clientUuid = self.crypto.randomUUID();
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
        script.src = '/fundraising/shared/js/message-notifications.js?v=' + Date.now();
        script.async = true;
        document.head.appendChild(script);
    }
})();
