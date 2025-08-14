// Modern Audit Page JavaScript

document.addEventListener('DOMContentLoaded', function() {
    console.log('Audit page loaded');
    
    // Initialize page functionality
    initializePage();
    
    // Add auto-refresh functionality
    initializeAutoRefresh();
});

// Initialize page functionality
function initializePage() {
    // Initialize tooltips
    initializeTooltips();
    
    // Add hover effects to timeline items
    addTimelineHoverEffects();
    
    // Initialize form auto-submit
    initializeFormAutoSubmit();
    
    // Add loading states to buttons
    addButtonLoadingStates();
}

// Initialize Bootstrap tooltips
function initializeTooltips() {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
}

// Add hover effects to timeline items
function addTimelineHoverEffects() {
    const timelineItems = document.querySelectorAll('.timeline-item');
    timelineItems.forEach(item => {
        const content = item.querySelector('.timeline-content');
        if (content) {
            item.addEventListener('mouseenter', function() {
                content.style.transform = 'translateY(-2px)';
                content.style.boxShadow = '0 6px 20px rgba(0,0,0,0.15)';
            });
            
            item.addEventListener('mouseleave', function() {
                content.style.transform = 'translateY(0)';
                content.style.boxShadow = '0 2px 8px rgba(0,0,0,0.08)';
            });
        }
    });
}

// Initialize form auto-submit
function initializeFormAutoSubmit() {
    const form = document.getElementById('auditFilterForm');
    if (!form) return;
    
    const selects = form.querySelectorAll('select');
    const dateInputs = form.querySelectorAll('input[type="date"]');
    
    // Add change listeners to selects and date inputs
    [...selects, ...dateInputs].forEach(input => {
        input.addEventListener('change', function() {
            addLoadingState(this);
            setTimeout(() => {
                form.submit();
            }, 100);
        });
    });
}

// Add loading states to buttons
function addButtonLoadingStates() {
    const exportButtons = document.querySelectorAll('a[href*="export=csv"]');
    exportButtons.forEach(button => {
        button.addEventListener('click', function() {
            const btn = this;
            const originalText = btn.innerHTML;
            
            // Add loading state
            btn.classList.add('loading');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Exporting...';
            
            // Remove loading state after 3 seconds
            setTimeout(() => {
                btn.classList.remove('loading');
                btn.innerHTML = originalText;
            }, 3000);
    });
});
}

// Add loading state to element
function addLoadingState(element) {
    element.style.opacity = '0.6';
    element.style.pointerEvents = 'none';
    
    // Create loading indicator
    const loader = document.createElement('div');
    loader.className = 'spinner-border spinner-border-sm text-primary';
    loader.style.position = 'absolute';
    loader.style.right = '10px';
    loader.style.top = '50%';
    loader.style.transform = 'translateY(-50%)';
    loader.id = 'loadingSpinner';
    
    // Add to parent if relative positioned
    const parent = element.parentElement;
    if (parent && getComputedStyle(parent).position !== 'static') {
        parent.appendChild(loader);
    }
}

// View audit details in modal
function viewDetails(data) {
    if (!data) {
        console.error('No data provided to viewDetails');
        return;
    }
    
    // Populate modal fields
    updateModalField('detailTimestamp', formatTimestamp(data.ts));
    updateModalField('detailUser', formatUser(data.user, data.role));
    updateModalField('detailAction', formatAction(data.action));
    updateModalField('detailEntity', data.entity || '-');
    updateModalField('detailIP', data.ip || '-');
    
    // Show/hide source row
    const sourceRow = document.getElementById('sourceRow');
    if (data.source && data.source.trim() !== '') {
        updateModalField('detailSource', data.source);
        sourceRow.style.display = 'block';
    } else {
        sourceRow.style.display = 'none';
    }
    
    // Populate changes
    updateChanges(data.before, data.after);
    
    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('detailsModal'));
    modal.show();
}

// Update modal field
function updateModalField(elementId, value) {
    const element = document.getElementById(elementId);
    if (element) {
        element.innerHTML = value;
    }
}

// Format timestamp
function formatTimestamp(timestamp) {
    if (!timestamp) return '-';
    
    try {
        const date = new Date(timestamp);
        return date.toLocaleDateString('en-GB', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });
    } catch (e) {
        return timestamp;
    }
}

// Format user
function formatUser(user, role) {
    if (!user || user === '-') return 'System';
    
    let formatted = escapeHtml(user);
    if (role && role.trim() !== '') {
        formatted += `<br><small class="text-muted">(${escapeHtml(role)})</small>`;
    }
    return formatted;
}

// Format action with badge
function formatAction(action) {
    if (!action) return '-';
    
    const actionColors = {
        'login': 'info', 'logout': 'secondary',
        'create': 'primary', 'create_pending': 'primary',
        'update': 'warning', 'delete': 'danger',
        'approve': 'success', 'reject': 'danger',
        'undo': 'warning'
    };
    
    const color = actionColors[action] || 'secondary';
    const displayAction = action.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase());
    
    return `<span class="badge bg-${color}">${escapeHtml(displayAction)}</span>`;
}

// Update changes display
function updateChanges(before, after) {
    const beforeElement = document.getElementById('detailBefore');
    const afterElement = document.getElementById('detailAfter');
    
    if (!beforeElement || !afterElement) return;
    
    // Format JSON or show raw text
    beforeElement.textContent = formatJSON(before) || 'No data';
    afterElement.textContent = formatJSON(after) || 'No data';
}

// Format JSON for display
function formatJSON(jsonString) {
    if (!jsonString || jsonString.trim() === '') return '';
    
    try {
        const obj = JSON.parse(jsonString);
        return JSON.stringify(obj, null, 2);
    } catch (e) {
        // Not valid JSON, return as is
        return jsonString;
    }
}

// Refresh data
function refreshData() {
    const btn = event.target.closest('.btn');
    if (!btn) return;
    
    const originalText = btn.innerHTML;
    
    // Add loading state
    btn.classList.add('loading');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Refreshing...';
    
    // Reload page
    setTimeout(() => {
        window.location.reload();
    }, 500);
}

// Auto-refresh functionality (every 30 seconds if no user activity)
function initializeAutoRefresh() {
    let lastActivity = Date.now();
    let autoRefreshInterval;
    
    // Track user activity
    document.addEventListener('mousemove', () => { lastActivity = Date.now(); });
    document.addEventListener('keypress', () => { lastActivity = Date.now(); });
    document.addEventListener('click', () => { lastActivity = Date.now(); });
    
    // Auto-refresh every 30 seconds if user is inactive for 2 minutes
    function startAutoRefresh() {
        autoRefreshInterval = setInterval(() => {
            const inactiveTime = Date.now() - lastActivity;
            if (inactiveTime > 120000) { // 2 minutes of inactivity
                // Only refresh if no modals are open
                const openModals = document.querySelectorAll('.modal.show');
                if (openModals.length === 0) {
                    window.location.reload();
                }
            }
        }, 30000); // Check every 30 seconds
    }
    
    // Start auto-refresh after page load
    setTimeout(startAutoRefresh, 10000); // Start after 10 seconds
}

// Utility function to escape HTML
function escapeHtml(text) {
    if (typeof text !== 'string') return text;
    
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    
    return text.replace(/[&<>"']/g, function(m) { return map[m]; });
}

// Export functionality
function exportAuditLogs(format = 'csv') {
    const currentUrl = new URL(window.location);
    currentUrl.searchParams.set('export', format);
    
    // Show loading state
    showLoadingMessage('Preparing export...');
    
    // Navigate to export URL
    window.location.href = currentUrl.toString();
    
    // Hide loading message after delay
    setTimeout(() => {
        hideLoadingMessage();
    }, 3000);
}

// Show loading message
function showLoadingMessage(message) {
    // Create toast notification
    const toast = document.createElement('div');
    toast.className = 'toast position-fixed top-0 end-0 m-3';
    toast.id = 'loadingToast';
    toast.setAttribute('role', 'alert');
    toast.innerHTML = `
        <div class="toast-header bg-primary text-white">
            <div class="spinner-border spinner-border-sm me-2" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <strong class="me-auto">Processing</strong>
        </div>
        <div class="toast-body">
            ${message}
        </div>
    `;
    
    document.body.appendChild(toast);
    const bsToast = new bootstrap.Toast(toast, { autohide: false });
    bsToast.show();
}

// Hide loading message
function hideLoadingMessage() {
    const toast = document.getElementById('loadingToast');
    if (toast) {
        const bsToast = bootstrap.Toast.getInstance(toast);
        if (bsToast) {
            bsToast.hide();
        }
        setTimeout(() => {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 500);
    }
}

// Global error handler
window.addEventListener('error', function(event) {
    console.error('JavaScript error:', event.error);
});

// Make functions globally available
window.viewDetails = viewDetails;
window.refreshData = refreshData;
window.exportAuditLogs = exportAuditLogs;