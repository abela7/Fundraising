/**
 * Donations Management JavaScript
 * Modern ES6+ with Bootstrap 5 Integration
 */

document.addEventListener('DOMContentLoaded', function() {
    // Initialize tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Auto-submit filters on change (with debounce for search)
    let searchTimeout;
    const searchInput = document.querySelector('input[name="search"]');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                submitFilters();
            }, 500);
        });
    }

    // Initialize any existing alerts to auto-dismiss
    const alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(alert => {
        setTimeout(() => {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        }, 5000);
    });
});

/**
 * Submit filters form automatically
 */
function submitFilters() {
    document.getElementById('filterForm').submit();
}

/**
 * View detailed information about a donation
 * @param {Object} donation - The donation object
 */
function viewDonationDetails(donation) {
    const modal = new bootstrap.Modal(document.getElementById('detailsModal'));
    const modalBody = document.getElementById('detailsModalBody');
    
    // Format the donation details
    const donorDisplay = donation.anonymous ? 
        '<span class="text-muted"><i class="fas fa-user-secret me-1"></i>Anonymous</span>' :
        `<strong>${escapeHtml(donation.donor_name || 'N/A')}</strong>`;
    
    const methodDisplay = donation.method ? 
        `<span class="badge bg-secondary"><i class="fas fa-${getMethodIcon(donation.method)} me-1"></i>${donation.method.charAt(0).toUpperCase() + donation.method.slice(1)}</span>` :
        '<span class="text-muted">N/A</span>';
    
    const statusBadge = `<span class="badge bg-${getStatusColor(donation.status)}">
        <i class="fas fa-${getStatusIcon(donation.status)} me-1"></i>${donation.status.charAt(0).toUpperCase() + donation.status.slice(1)}
    </span>`;
    
    const typeBadge = donation.donation_type === 'payment' ?
        '<span class="badge bg-success"><i class="fas fa-credit-card me-1"></i>Payment</span>' :
        '<span class="badge bg-warning"><i class="fas fa-handshake me-1"></i>Pledge</span>';
    
    const packageDisplay = donation.package_label ?
        `${escapeHtml(donation.package_label)}${donation.package_sqm ? ` (${parseFloat(donation.package_sqm).toFixed(2)} m²)` : ''}` :
        'Custom Amount';
    
    modalBody.innerHTML = `
        <div class="detail-grid">
            <div class="detail-item">
                <label>ID & Type</label>
                <div class="value">
                    <span class="text-primary fw-bold me-2">${donation.donation_type.toUpperCase().charAt(0)}${String(donation.id).padStart(4, '0')}</span>
                    ${typeBadge}
                </div>
            </div>
            <div class="detail-item">
                <label>Status</label>
                <div class="value">${statusBadge}</div>
            </div>
            <div class="detail-item">
                <label>Donor Information</label>
                <div class="value">
                    ${donorDisplay}
                    ${donation.donor_phone && !donation.anonymous ? `<br><small class="text-muted">${escapeHtml(donation.donor_phone)}</small>` : ''}
                    ${donation.donor_email && !donation.anonymous ? `<br><small class="text-muted">${escapeHtml(donation.donor_email)}</small>` : ''}
                </div>
            </div>
            <div class="detail-item">
                <label>Amount</label>
                <div class="value">
                    <span class="h5 text-success mb-0">£${parseFloat(donation.amount).toLocaleString('en-GB', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
                </div>
            </div>
            <div class="detail-item">
                <label>Payment Method</label>
                <div class="value">${methodDisplay}</div>
            </div>
            <div class="detail-item">
                <label>Package</label>
                <div class="value">${packageDisplay}</div>
            </div>
            <div class="detail-item">
                <label>Created Date</label>
                <div class="value">${formatDateTime(donation.created_at)}</div>
            </div>
            <div class="detail-item">
                <label>Processed Date</label>
                <div class="value">${donation.processed_at ? formatDateTime(donation.processed_at) : '<span class="text-muted">Not processed</span>'}</div>
            </div>
            <div class="detail-item">
                <label>Processed By</label>
                <div class="value">${escapeHtml(donation.processed_by || 'System')}</div>
            </div>
            ${donation.notes ? `
            <div class="detail-item full-width">
                <label>Notes/Reference</label>
                <div class="value">${escapeHtml(donation.notes)}</div>
            </div>
            ` : ''}
        </div>
    `;
    
    modal.show();
}

/**
 * Edit a donation (payment or pledge)
 * @param {Object} donation - The donation object
 */
function editDonation(donation) {
    const modal = new bootstrap.Modal(document.getElementById('editModal'));
    const modalBody = document.getElementById('editModalBody');
    const form = document.getElementById('editForm');
    
    // Set form action based on donation type
    const actionValue = donation.donation_type === 'payment' ? 'update_payment' : 'update_pledge';
    const idFieldName = donation.donation_type === 'payment' ? 'payment_id' : 'pledge_id';
    
    let formContent = `
        <input type="hidden" name="action" value="${actionValue}">
        <input type="hidden" name="${idFieldName}" value="${donation.id}">
        
        <div class="row g-3">
            <div class="col-12">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    Editing ${donation.donation_type} #${donation.donation_type.toUpperCase().charAt(0)}${String(donation.id).padStart(4, '0')}
                </div>
            </div>
            
            <div class="col-md-6">
                <label class="form-label">Donor Name <span class="text-danger">*</span></label>
                <input type="text" name="donor_name" class="form-control" value="${escapeHtml(donation.donor_name || '')}" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Phone Number</label>
                <input type="tel" name="donor_phone" class="form-control" value="${escapeHtml(donation.donor_phone || '')}">
            </div>
            <div class="col-12">
                <label class="form-label">Email Address</label>
                <input type="email" name="donor_email" class="form-control" value="${escapeHtml(donation.donor_email || '')}">
            </div>
            <div class="col-md-6">
                <label class="form-label">Amount <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text">£</span>
                    <input type="number" name="amount" class="form-control" value="${parseFloat(donation.amount).toFixed(2)}" step="0.01" min="0.01" required>
                </div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Status <span class="text-danger">*</span></label>
                <select name="status" class="form-select" required>
                    <option value="pending" ${donation.status === 'pending' ? 'selected' : ''}>Pending</option>
                    <option value="approved" ${donation.status === 'approved' ? 'selected' : ''}>Approved</option>
                    <option value="rejected" ${donation.status === 'rejected' ? 'selected' : ''}>Rejected</option>
                    <option value="voided" ${donation.status === 'voided' ? 'selected' : ''}>Voided</option>
                </select>
            </div>
    `;
    
    if (donation.donation_type === 'payment') {
        formContent += `
            <div class="col-md-6">
                <label class="form-label">Payment Method <span class="text-danger">*</span></label>
                <select name="method" class="form-select" required>
                    <option value="cash" ${donation.method === 'cash' ? 'selected' : ''}>Cash</option>
                    <option value="bank" ${donation.method === 'bank' ? 'selected' : ''}>Bank Transfer</option>
                    <option value="card" ${donation.method === 'card' ? 'selected' : ''}>Card</option>
                    <option value="other" ${donation.method === 'other' ? 'selected' : ''}>Other</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Reference</label>
                <input type="text" name="reference" class="form-control" value="${escapeHtml(donation.notes || '')}">
            </div>
        `;
    } else {
        formContent += `
            <div class="col-md-6">
                <div class="form-check mt-4">
                    <input type="checkbox" name="anonymous" class="form-check-input" ${donation.anonymous ? 'checked' : ''}>
                    <label class="form-check-label">Anonymous Donation</label>
                </div>
            </div>
            <div class="col-12">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="3">${escapeHtml(donation.notes || '')}</textarea>
            </div>
        `;
    }
    
    formContent += `
        </div>
    `;
    
    modalBody.innerHTML = formContent;
    modal.show();
}

/**
 * Delete a donation with confirmation
 * @param {string} type - 'payment' or 'pledge'
 * @param {number} id - The donation ID
 * @param {string} donorName - The donor name for display
 */
function deleteDonation(type, id, donorName) {
    const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
    const deleteDetails = document.getElementById('deleteDetails');
    const deleteAction = document.getElementById('deleteAction');
    const deletePaymentId = document.getElementById('deletePaymentId');
    const deletePledgeId = document.getElementById('deletePledgeId');
    
    // Set up the form
    deleteAction.value = type === 'payment' ? 'delete_payment' : 'delete_pledge';
    deletePaymentId.value = type === 'payment' ? id : '';
    deletePledgeId.value = type === 'pledge' ? id : '';
    
    // Display donation details
    const donorDisplay = donorName || 'Anonymous';
    const typeDisplay = type.charAt(0).toUpperCase() + type.slice(1);
    const idDisplay = type.toUpperCase().charAt(0) + String(id).padStart(4, '0');
    
    deleteDetails.innerHTML = `
        <div class="alert alert-warning">
            <strong>${typeDisplay} #${idDisplay}</strong><br>
            Donor: ${escapeHtml(donorDisplay)}
        </div>
    `;
    
    modal.show();
}

/**
 * Export donations data
 */
function exportDonations() {
    const params = new URLSearchParams(window.location.search);
    params.set('export', 'csv');
    window.location.href = '?' + params.toString();
}

/**
 * Utility Functions
 */

function escapeHtml(text) {
    if (!text) return '';
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, function(m) { return map[m]; });
}

function formatDateTime(dateString) {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    const options = {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: true
    };
    return date.toLocaleDateString('en-GB', options);
}

function getStatusColor(status) {
    const colors = {
        'pending': 'warning',
        'approved': 'success',
        'rejected': 'danger',
        'voided': 'secondary'
    };
    return colors[status] || 'secondary';
}

function getStatusIcon(status) {
    const icons = {
        'pending': 'clock',
        'approved': 'check-circle',
        'rejected': 'times-circle',
        'voided': 'ban'
    };
    return icons[status] || 'question-circle';
}

function getMethodIcon(method) {
    const icons = {
        'cash': 'money-bill-wave',
        'bank': 'university',
        'card': 'credit-card',
        'other': 'question-circle'
    };
    return icons[method] || 'question-circle';
}

/**
 * Mobile-specific enhancements
 */
if (window.innerWidth <= 768) {
    // Collapse filters by default on mobile
    const filtersCollapse = document.getElementById('filtersCollapse');
    if (filtersCollapse) {
        const bsCollapse = new bootstrap.Collapse(filtersCollapse, {
            toggle: false
        });
        bsCollapse.hide();
    }
    
    // Add touch-friendly interactions
    const tableRows = document.querySelectorAll('.donation-row');
    tableRows.forEach(row => {
        row.addEventListener('touchstart', function() {
            this.style.backgroundColor = 'var(--gray-100)';
        });
        row.addEventListener('touchend', function() {
            setTimeout(() => {
                this.style.backgroundColor = '';
            }, 150);
        });
    });
}

/**
 * Accessibility enhancements
 */
document.addEventListener('keydown', function(e) {
    // Close modals on Escape key
    if (e.key === 'Escape') {
        const openModals = document.querySelectorAll('.modal.show');
        openModals.forEach(modal => {
            const bsModal = bootstrap.Modal.getInstance(modal);
            if (bsModal) bsModal.hide();
        });
    }
});

/**
 * Auto-refresh data every 5 minutes (optional)
 */
let autoRefreshEnabled = false;
if (autoRefreshEnabled) {
    setInterval(() => {
        // Only refresh if no modals are open
        const openModals = document.querySelectorAll('.modal.show');
        if (openModals.length === 0) {
            location.reload();
        }
    }, 300000); // 5 minutes
}

/**
 * Performance monitoring
 */
if ('performance' in window) {
    window.addEventListener('load', function() {
        const loadTime = performance.timing.loadEventEnd - performance.timing.navigationStart;
        console.log(`Donations Management page loaded in ${loadTime}ms`);
    });
}
