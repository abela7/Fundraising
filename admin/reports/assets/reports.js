// Modern Reports Page JavaScript

document.addEventListener('DOMContentLoaded', function() {
    console.log('Reports page loaded');
    
    // Initialize page functionality
    initializePage();
    
    // Set default date values for custom modal
    setDefaultDates();
});

// Initialize page functionality
function initializePage() {
    // Add hover effects to report cards
    addCardHoverEffects();
    
    // Initialize tooltips if any
    initializeTooltips();
    
    // Add loading states to buttons when clicked
    addButtonLoadingStates();
}

// Add hover effects to report cards
function addCardHoverEffects() {
    const reportCards = document.querySelectorAll('.report-card');
    reportCards.forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-3px)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
        });
    });
}

// Initialize Bootstrap tooltips
function initializeTooltips() {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
}

// Add loading states to download buttons
function addButtonLoadingStates() {
    const downloadLinks = document.querySelectorAll('a[href*="report="]');
    downloadLinks.forEach(link => {
        link.addEventListener('click', function() {
            const btn = this;
            const originalText = btn.innerHTML;
            
            // Add loading state
            btn.classList.add('loading');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Generating...';
            
            // Remove loading state after 3 seconds (or when page unloads)
        setTimeout(() => {
                btn.classList.remove('loading');
                btn.innerHTML = originalText;
            }, 3000);
        });
    });
}

// Set default dates for custom modal
function setDefaultDates() {
    const today = new Date();
    const firstDayOfMonth = new Date(today.getFullYear(), today.getMonth(), 1);
    
    // Format dates for input fields
    const formatDate = (date) => {
        return date.toISOString().split('T')[0];
    };
    
    // Set default values when modal is shown
    document.addEventListener('shown.bs.modal', function(event) {
        if (event.target.id === 'customDateModal') {
            const fromDate = document.getElementById('modalFromDate');
            const toDate = document.getElementById('modalToDate');
            
            if (fromDate && toDate) {
                fromDate.value = formatDate(firstDayOfMonth);
                toDate.value = formatDate(today);
            }
        }
    });
}

// Show custom date modal
function showCustomDateModal(reportType) {
    const modal = document.getElementById('customDateModal');
    const reportTypeInput = document.getElementById('modalReportType');
    
    if (modal && reportTypeInput) {
        reportTypeInput.value = reportType;
        const bsModal = new bootstrap.Modal(modal);
        bsModal.show();
    }
}

// Generate custom report
function generateCustomReport() {
    const reportType = document.getElementById('modalReportType').value;
    const fromDate = document.getElementById('modalFromDate').value;
    const toDate = document.getElementById('modalToDate').value;
    
    if (!fromDate || !toDate) {
        alert('Please select both from and to dates.');
        return;
    }
    
    if (new Date(fromDate) > new Date(toDate)) {
        alert('From date cannot be later than to date.');
        return;
    }
    
    // Build URL with custom parameters
    const url = `?report=${reportType}&format=csv&date=custom&from=${fromDate}&to=${toDate}`;
    
    // Add loading state to button
    const btn = document.querySelector('#customDateModal .btn-primary');
    const originalText = btn.innerHTML;
    btn.classList.add('loading');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Generating...';
    
    // Navigate to generate report
    window.location.href = url;
    
    // Hide modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('customDateModal'));
    modal.hide();
    
    // Reset button after a delay
    setTimeout(() => {
        btn.classList.remove('loading');
        btn.innerHTML = originalText;
    }, 2000);
}

// Refresh data
function refreshData() {
    const btn = event.target.closest('.btn');
    const originalText = btn.innerHTML;
    
    // Add loading state
    btn.classList.add('loading');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Refreshing...';
    
    // Reload page
    setTimeout(() => {
        window.location.reload();
    }, 500);
}

// Format currency
function formatCurrency(amount, currency = 'GBP') {
    return new Intl.NumberFormat('en-GB', {
        style: 'currency',
        currency: currency
    }).format(amount);
}

// Format date
function formatDate(dateString) {
    return new Date(dateString).toLocaleDateString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric'
    });
}

// Print current page
function printPage() {
    window.print();
}

// Export data as JSON (for advanced users)
function exportJSON(data, filename) {
    const blob = new Blob([JSON.stringify(data, null, 2)], {
        type: 'application/json'
    });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

// Show success message
function showSuccessMessage(message) {
    // Create toast notification
    const toast = document.createElement('div');
    toast.className = 'toast position-fixed top-0 end-0 m-3';
    toast.setAttribute('role', 'alert');
    toast.innerHTML = `
        <div class="toast-header bg-success text-white">
            <i class="fas fa-check-circle me-2"></i>
            <strong class="me-auto">Success</strong>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
        </div>
        <div class="toast-body">
            ${message}
        </div>
    `;
    
    document.body.appendChild(toast);
    const bsToast = new bootstrap.Toast(toast);
    bsToast.show();
    
    // Remove from DOM after hiding
    toast.addEventListener('hidden.bs.toast', () => {
        document.body.removeChild(toast);
    });
}

// Show error message
function showErrorMessage(message) {
    // Create toast notification
    const toast = document.createElement('div');
    toast.className = 'toast position-fixed top-0 end-0 m-3';
    toast.setAttribute('role', 'alert');
    toast.innerHTML = `
        <div class="toast-header bg-danger text-white">
            <i class="fas fa-exclamation-circle me-2"></i>
            <strong class="me-auto">Error</strong>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
        </div>
        <div class="toast-body">
            ${message}
        </div>
    `;
    
    document.body.appendChild(toast);
    const bsToast = new bootstrap.Toast(toast);
    bsToast.show();
    
    // Remove from DOM after hiding
    toast.addEventListener('hidden.bs.toast', () => {
        document.body.removeChild(toast);
    });
}

// Global error handler
window.addEventListener('error', function(event) {
    console.error('JavaScript error:', event.error);
});

// Make functions globally available
window.showCustomDateModal = showCustomDateModal;
window.generateCustomReport = generateCustomReport;
window.refreshData = refreshData;
window.formatCurrency = formatCurrency;
window.formatDate = formatDate;
window.printPage = printPage;
window.exportJSON = exportJSON;
window.showSuccessMessage = showSuccessMessage;
window.showErrorMessage = showErrorMessage;