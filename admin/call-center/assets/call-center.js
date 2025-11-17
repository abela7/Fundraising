/**
 * Call Center JavaScript
 * Handles queue management, timers, and UI interactions
 */

(function() {
    'use strict';

    // Auto-refresh functionality
    const AUTO_REFRESH_INTERVAL = 120000; // 2 minutes
    let refreshTimer = null;

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        initializeCallCenter();
    });

    function initializeCallCenter() {
        // Start auto-refresh timer if on dashboard
        if (window.location.pathname.includes('call-center/index.php') || 
            window.location.pathname.endsWith('call-center/')) {
            startAutoRefresh();
        }

        // Add event listeners
        setupEventListeners();
        
        // Show any success messages
        showSessionMessages();
    }

    function setupEventListeners() {
        // Phone number click tracking
        document.querySelectorAll('.phone-link').forEach(link => {
            link.addEventListener('click', function(e) {
                // Log phone click (could send to analytics)
                console.log('Phone number clicked:', this.textContent);
            });
        });

        // Queue item hover effects
        document.querySelectorAll('.table-hover tbody tr').forEach(row => {
            row.addEventListener('mouseenter', function() {
                this.style.backgroundColor = '#f7fafc';
            });
            row.addEventListener('mouseleave', function() {
                this.style.backgroundColor = '';
            });
        });

        // Confirm before marking as not interested
        document.querySelectorAll('[name="disposition"]').forEach(select => {
            select.addEventListener('change', function() {
                if (this.value === 'mark_as_not_interested') {
                    if (!confirm('Are you sure you want to mark this donor as not interested? This will remove them from the active queue.')) {
                        this.value = 'no_action_needed';
                    }
                }
            });
        });
    }

    function startAutoRefresh() {
        // Clear any existing timer
        if (refreshTimer) {
            clearInterval(refreshTimer);
        }

        // Set up new timer
        refreshTimer = setInterval(function() {
            console.log('Auto-refreshing queue...');
            // Reload page to get fresh data
            window.location.reload();
        }, AUTO_REFRESH_INTERVAL);

        // Show countdown
        updateRefreshCountdown();
    }

    function updateRefreshCountdown() {
        const countdownElement = document.getElementById('refreshCountdown');
        if (!countdownElement) return;

        let secondsRemaining = AUTO_REFRESH_INTERVAL / 1000;
        
        const countdownTimer = setInterval(function() {
            secondsRemaining--;
            
            const minutes = Math.floor(secondsRemaining / 60);
            const seconds = secondsRemaining % 60;
            
            countdownElement.textContent = `Auto-refresh in ${minutes}:${seconds.toString().padStart(2, '0')}`;
            
            if (secondsRemaining <= 0) {
                clearInterval(countdownTimer);
            }
        }, 1000);
    }

    function showSessionMessages() {
        // Check for success message in session storage
        const successMessage = sessionStorage.getItem('call_center_success');
        if (successMessage) {
            showAlert('success', successMessage);
            sessionStorage.removeItem('call_center_success');
        }

        // Check for error message
        const errorMessage = sessionStorage.getItem('call_center_error');
        if (errorMessage) {
            showAlert('danger', errorMessage);
            sessionStorage.removeItem('call_center_error');
        }
    }

    function showAlert(type, message) {
        const alertHtml = `
            <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        `;
        
        const mainContent = document.querySelector('.main-content');
        if (mainContent) {
            mainContent.insertAdjacentHTML('afterbegin', alertHtml);
            
            // Auto-dismiss after 5 seconds
            setTimeout(function() {
                const alert = mainContent.querySelector('.alert');
                if (alert) {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }
            }, 5000);
        }
    }

    // Export functions for global access
    window.CallCenter = {
        refreshQueue: function() {
            window.location.reload();
        },
        
        startCall: function(donorId, queueId) {
            window.location.href = `make-call.php?donor_id=${donorId}&queue_id=${queueId}`;
        },
        
        viewHistory: function(donorId) {
            window.location.href = `call-history.php?donor_id=${donorId}`;
        }
    };

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl/Cmd + R to refresh queue
        if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
            e.preventDefault();
            window.location.reload();
        }
    });

    // Warn before leaving if there's unsaved data
    let hasUnsavedChanges = false;
    
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('change', function() {
            hasUnsavedChanges = true;
        });
        
        form.addEventListener('submit', function() {
            hasUnsavedChanges = false;
        });
    });

    window.addEventListener('beforeunload', function(e) {
        if (hasUnsavedChanges) {
            e.preventDefault();
            e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
            return e.returnValue;
        }
    });

    // Performance tracking
    if (window.performance && window.performance.timing) {
        window.addEventListener('load', function() {
            const loadTime = window.performance.timing.loadEventEnd - window.performance.timing.navigationStart;
            console.log(`Page loaded in ${loadTime}ms`);
        });
    }

    // Service Worker registration (for future offline support)
    if ('serviceWorker' in navigator) {
        // Uncomment when service worker is implemented
        // navigator.serviceWorker.register('/sw.js')
        //     .then(reg => console.log('Service Worker registered'))
        //     .catch(err => console.log('Service Worker registration failed'));
    }

})();

// Utility functions
function formatPhoneNumber(phone) {
    // Format UK phone number
    if (phone.startsWith('07')) {
        return phone.replace(/(\d{5})(\d{6})/, '$1 $2');
    }
    return phone;
}

function formatDuration(seconds) {
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = seconds % 60;
    
    if (hours > 0) {
        return `${hours}:${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
    }
    return `${minutes}:${secs.toString().padStart(2, '0')}`;
}

function formatCurrency(amount) {
    return '£' + parseFloat(amount).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
}

// Export utility functions
window.CallCenterUtils = {
    formatPhoneNumber,
    formatDuration,
    formatCurrency
};

