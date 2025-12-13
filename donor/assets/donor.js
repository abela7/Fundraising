// Donor Portal JavaScript - Mobile First - Matching Registrar Portal

// DOM ready
document.addEventListener('DOMContentLoaded', function() {
    // Initialize components
    initSidebar();
    initTooltips();
    
    // Initialize tour if this is first login
    if (window.showDonorTour) {
        initDonorTour();
    }
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

    // On desktop, sidebar is expanded by default (not collapsed)
    // The collapse state can be toggled by the user via desktopSidebarToggle
    // Removed auto-collapse to fix alignment issue

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

// Initialize Bootstrap tooltips
function initTooltips() {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
}

// Auto-hide alerts after 5 seconds (except persistent ones)
document.addEventListener('DOMContentLoaded', function() {
    const alerts = document.querySelectorAll('.alert:not(.alert-persistent)');
    alerts.forEach(alert => {
        setTimeout(() => {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        }, 5000);
    });
});

// Initialize Donor Portal Tour - Modern & Mobile-Friendly
function initDonorTour() {
    // Check if Driver.js is available
    if (typeof driver === 'undefined') {
        console.error('Driver.js not loaded. Tour cannot be initialized.');
        // Try again after a delay
        setTimeout(function() {
            if (typeof driver !== 'undefined') {
                initDonorTour();
            } else {
                console.error('Driver.js still not available after retry');
            }
        }, 1000);
        return;
    }

    console.log('✅ Initializing donor portal tour...');
    
    // Show a brief loading message
    const loadingMsg = document.createElement('div');
    loadingMsg.id = 'tour-loading';
    loadingMsg.style.cssText = 'position: fixed; top: 20px; right: 20px; background: linear-gradient(135deg, #0a6286 0%, #0a8fb5 100%); color: white; padding: 12px 24px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.2); z-index: 10001; font-family: Inter, sans-serif; font-size: 14px; animation: slideInRight 0.3s ease-out;';
    loadingMsg.innerHTML = '<i class="fas fa-rocket" style="margin-right: 8px;"></i> Starting your tour...';
    document.body.appendChild(loadingMsg);
    
    // Add animation
    const style = document.createElement('style');
    style.textContent = '@keyframes slideInRight { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }';
    document.head.appendChild(style);
    
    setTimeout(() => {
        if (loadingMsg && loadingMsg.parentNode) {
            loadingMsg.style.animation = 'slideOutRight 0.3s ease-out forwards';
            setTimeout(() => loadingMsg.remove(), 300);
        }
    }, 1500);
    
    console.log('📍 Checking tour elements...');

    // Build tour steps dynamically
    const steps = [];

    // Step 1: Welcome
    steps.push({
        element: '[data-tour-step="welcome"]',
        popover: {
            title: '👋 Welcome to Your Donor Portal!',
            description: 'This quick interactive tour will guide you through all the features. Take just 2 minutes to discover how easy it is to manage your giving!',
            side: 'bottom',
            align: 'center'
        }
    });

    // Step 2: Progress Bar (if exists)
    if (document.querySelector('[data-tour-step="progress"]')) {
        steps.push({
            element: '[data-tour-step="progress"]',
            popover: {
                title: '📊 Track Your Progress',
                description: 'See exactly how much you\'ve paid towards your pledge goal. The visual progress bar makes it easy to stay on track!',
                side: 'bottom',
                align: 'start'
            }
        });
    }

    // Step 3: Quick Actions
    steps.push({
        element: '[data-tour-step="quick-actions"]',
        popover: {
            title: '⚡ Quick Actions',
            description: '<div style="text-align: left;"><p><strong>Everything you need in one place:</strong></p><ul style="margin: 10px 0; padding-left: 20px;"><li><strong>Make Payment</strong> - Submit a payment instantly</li><li><strong>Payment History</strong> - View all past payments</li><li><strong>My Plan</strong> - Check your payment schedule</li><li><strong>Update Pledge</strong> - Increase your commitment</li><li><strong>Contact Us</strong> - Get help anytime</li></ul></div>',
            side: 'top',
            align: 'center'
        }
    });

    // Step 4: Quick Stats
    steps.push({
        element: '[data-tour-step="stats"]',
        popover: {
            title: '💰 Your Financial Summary',
            description: '<div style="text-align: left;"><p>All your important numbers at a glance:</p><ul style="margin: 10px 0; padding-left: 20px;"><li><strong>Total Pledged</strong> - Your commitment</li><li><strong>Total Paid</strong> - What you\'ve given</li><li><strong>Balance</strong> - What remains</li><li><strong>Payments Made</strong> - Your progress</li></ul></div>',
            side: 'top',
            align: 'center'
        }
    });

    // Step 5: Payment Plan (if exists)
    if (document.querySelector('[data-tour-step="payment-plan"]')) {
        steps.push({
            element: '[data-tour-step="payment-plan"]',
            popover: {
                title: '📅 Your Payment Plan',
                description: 'Your active payment plan shows monthly amounts, next due dates, and progress. Click to view the full schedule or make a payment!',
                side: 'top',
                align: 'center'
            }
        });
    }

    // Step 6: Navigation Menu
    const sidebarToggle = document.getElementById('sidebarToggle');
    if (sidebarToggle) {
        steps.push({
            element: '#sidebarToggle',
            popover: {
                title: '📱 Navigation Menu',
                description: 'Tap this menu button anytime to access all features. Everything is organized into easy-to-find sections!',
                side: 'bottom',
                align: 'start'
            }
        });
    }

    // Final step
    steps.push({
        popover: {
            title: '🎉 You\'re All Set!',
            description: '<div style="text-align: center;"><p style="font-size: 1.1em; margin: 15px 0;">You now know how to navigate your donor portal!</p><p>Feel free to explore and reach out if you need any help.</p><p style="margin-top: 15px;"><strong>Thank you for your generosity! ❤️</strong></p></div>',
        }
    });

    // Initialize Driver.js with modern, mobile-friendly settings
    const driverObj = driver({
        showProgress: true,
        showButtons: ['next', 'previous', 'close'],
        steps: steps,
        nextBtnText: 'Next →',
        prevBtnText: '← Back',
        doneBtnText: 'Get Started! 🚀',
        closeBtnText: 'Skip',
        progressText: '{{current}} of {{total}}',
        overlayColor: 'rgba(0, 0, 0, 0.7)',
        smoothScroll: true,
        allowClose: true,
        disableActiveInteraction: false,
        popoverClass: 'donor-tour-popover',
        onDestroyStarted: () => {
            // Mark tour as completed when user closes it
            localStorage.setItem('donor-portal-tour-completed', 'true');
            driverObj.destroy();
        },
        onDestroyed: () => {
            // Mark tour as completed
            localStorage.setItem('donor-portal-tour-completed', 'true');
            console.log('Tour completed!');
        }
    });

    // Start the tour after ensuring page is fully loaded
    setTimeout(() => {
        console.log('🚀 Starting tour with ' + steps.length + ' steps');
        try {
            driverObj.drive();
        } catch (error) {
            console.error('Error starting tour:', error);
            // Mark as completed to prevent retry loops
            localStorage.setItem('donor-portal-tour-completed', 'true');
        }
    }, 1800);
}
