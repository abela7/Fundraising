// Modern Payments Page JavaScript

document.addEventListener('DOMContentLoaded', function() {
    console.log('Payments page loaded');
    
    // Initialize tooltips
    initializeTooltips();
    
    // Initialize form validation
    initializeFormValidation();
    
    // Initialize auto-refresh
    initializeAutoRefresh();

    // Ensure modal/backdrop cleanup for safety (any modal)
    document.addEventListener('hidden.bs.modal', function() {
        document.body.classList.remove('modal-open');
        const backdrops = document.querySelectorAll('.modal-backdrop');
        backdrops.forEach(el => el.parentNode && el.parentNode.removeChild(el));
    });
});

// Initialize Bootstrap tooltips
function initializeTooltips() {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
}

// Package selection functionality
function updateAmountFromPackage() {
    const packageSelect = document.getElementById('packageSelect');
    const amountInput = document.getElementById('amountInput');
    
    if (packageSelect && amountInput) {
        const selectedOption = packageSelect.options[packageSelect.selectedIndex];
        const price = selectedOption.getAttribute('data-price');
        
        if (price && price !== '') {
            amountInput.value = parseFloat(price).toFixed(2);
        }
    }
}

// View payment details modal
function viewPaymentDetails(paymentId) {
    if (!paymentId) {
        console.error('Payment ID is required');
        return;
    }
    
    // Show loading state
    showLoadingModal();
    
    // Fetch payment details
    fetch(`?action=get_payment_details&id=${paymentId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                populatePaymentDetailsModal(data.payment);
                showPaymentDetailsModal();
            } else {
                alert('Error loading payment details: ' + (data.error || 'Unknown error'));
                hideModalById('paymentDetailsModal');
            }
        })
        .catch(error => {
            console.error('Error fetching payment details:', error);
            alert('Failed to load payment details. Please try again.');
            hideModalById('paymentDetailsModal');
        })
        ;
}

// Show loading modal
function showLoadingModal() {
    const modal = document.getElementById('paymentDetailsModal');
    if (modal) {
        const modalBody = modal.querySelector('.modal-body');
        modalBody.innerHTML = `
            <div class="text-center py-4">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mt-2 text-muted">Loading payment details...</p>
            </div>
        `;
        const instance = bootstrap.Modal.getOrCreateInstance(modal);
        instance.show();
    }
}

// Hide loading modal
function hideLoadingModal() {
    // No-op: we reuse the same modal and replace its content
}

function hideModalById(id) {
    const el = document.getElementById(id);
    if (!el) return;
    const instance = bootstrap.Modal.getOrCreateInstance(el);
    instance.hide();
}

// Populate payment details modal
function populatePaymentDetailsModal(payment) {
    const modal = document.getElementById('paymentDetailsModal');
    if (!modal || !payment) return;
    
    const modalBody = modal.querySelector('.modal-body');
    const statusColors = {
        'pending': 'warning',
        'approved': 'success',
        'voided': 'danger'
    };
    
    const statusColor = statusColors[payment.status] || 'secondary';
    const formattedDate = new Date(payment.created_at).toLocaleDateString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
    
    modalBody.innerHTML = `
        <div class="payment-detail-grid">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-bold">Payment ID:</label>
                    <p class="mb-0">#PAY${payment.id.toString().padStart(6, '0')}</p>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold">Date & Time:</label>
                    <p class="mb-0">${formattedDate}</p>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold">Donor:</label>
                    <p class="mb-0">${payment.donor_name || 'Anonymous'}</p>
                    ${payment.donor_phone ? `<small class="text-muted">${payment.donor_phone}</small>` : ''}
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold">Amount:</label>
                    <p class="mb-0 text-success fw-bold fs-5">£${parseFloat(payment.amount).toFixed(2)}</p>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold">Payment Method:</label>
                    <p class="mb-0">${payment.method.charAt(0).toUpperCase() + payment.method.slice(1)}</p>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold">Status:</label>
                    <p class="mb-0">
                        <span class="badge bg-${statusColor}">
                            ${payment.status.charAt(0).toUpperCase() + payment.status.slice(1)}
                        </span>
                    </p>
                </div>
                ${payment.package_label ? `
                <div class="col-md-6">
                    <label class="form-label fw-bold">Package:</label>
                    <p class="mb-0">${payment.package_label}</p>
                </div>
                ` : ''}
                ${payment.reference ? `
                <div class="col-md-6">
                    <label class="form-label fw-bold">Reference:</label>
                    <p class="mb-0">${payment.reference}</p>
                </div>
                ` : ''}
                ${payment.received_by_name ? `
                <div class="col-12">
                    <label class="form-label fw-bold">Recorded By:</label>
                    <p class="mb-0">${payment.received_by_name}</p>
                </div>
                ` : ''}
            </div>
        </div>
    `;
}

// Show payment details modal
function showPaymentDetailsModal() {
    const modal = document.getElementById('paymentDetailsModal');
    if (modal) {
        const instance = bootstrap.Modal.getOrCreateInstance(modal);
        instance.show();
    }
}

// Form validation
function initializeFormValidation() {
    const form = document.getElementById('recordPaymentForm');
    if (!form) return;
    
    form.addEventListener('submit', function(event) {
        if (!form.checkValidity()) {
            event.preventDefault();
            event.stopPropagation();
        }
        form.classList.add('was-validated');
    });
    
    // Real-time validation for amount field
    const amountInput = document.getElementById('amountInput');
    if (amountInput) {
        amountInput.addEventListener('input', function() {
            const value = parseFloat(this.value);
            if (value <= 0) {
                this.setCustomValidity('Amount must be greater than zero');
            } else {
                this.setCustomValidity('');
            }
        });
    }
}

// Auto-refresh functionality (optional)
function initializeAutoRefresh() {
    // Only auto-refresh if user is not actively interacting
    let lastActivity = Date.now();
    let autoRefreshInterval;
    
    // Track user activity
    document.addEventListener('mousemove', () => { lastActivity = Date.now(); });
    document.addEventListener('keypress', () => { lastActivity = Date.now(); });
    document.addEventListener('click', () => { lastActivity = Date.now(); });
    
    // Auto-refresh every 2 minutes if user is inactive for 1 minute
    function startAutoRefresh() {
        autoRefreshInterval = setInterval(() => {
            const inactiveTime = Date.now() - lastActivity;
            if (inactiveTime > 60000) { // 1 minute of inactivity
                // Only refresh if no modals are open
                const openModals = document.querySelectorAll('.modal.show');
                if (openModals.length === 0) {
                    window.location.reload();
                }
            }
        }, 120000); // Check every 2 minutes
    }
    
    // Start auto-refresh after page load
    setTimeout(startAutoRefresh, 5000);
}

// Export functionality (placeholder)
function exportPayments() {
    alert('Export functionality will be implemented soon.');
}

// Utility function to format currency
function formatCurrency(amount, currency = 'GBP') {
    return new Intl.NumberFormat('en-GB', {
        style: 'currency',
        currency: currency
    }).format(amount);
}

// Utility function to format date
function formatDate(dateString) {
    return new Date(dateString).toLocaleDateString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

// Handle filter changes with loading states
function handleFilterChange() {
    const filterForm = document.getElementById('filterForm');
    if (filterForm) {
        // Add loading state to submit button
        const submitBtn = filterForm.querySelector('button[type="submit"]');
        if (submitBtn) {
            submitBtn.classList.add('loading');
            submitBtn.disabled = true;
        }
        
        // Submit form
        filterForm.submit();
    }
}

// Global error handler
window.addEventListener('error', function(event) {
    console.error('JavaScript error:', event.error);
});

// Make functions globally available
window.updateAmountFromPackage = updateAmountFromPackage;
window.viewPaymentDetails = viewPaymentDetails;
window.exportPayments = exportPayments;