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

// Initialize Donor Portal Tour
function initDonorTour() {
    // Check if Shepherd.js is available
    if (typeof Shepherd === 'undefined') {
        console.warn('Shepherd.js not loaded. Tour cannot be initialized.');
        return;
    }

    const tour = new Shepherd.Tour({
        useModalOverlay: true,
        defaultStepOptions: {
            cancelIcon: {
                enabled: true
            },
            classes: 'shepherd-theme-custom',
            scrollTo: {
                behavior: 'smooth',
                block: 'center'
            },
            buttons: [
                {
                    text: 'Skip Tour',
                    action: function() {
                        return this.cancel();
                    },
                    classes: 'shepherd-button-secondary'
                }
            ]
        }
    });

    // Step 1: Welcome
    tour.addStep({
        id: 'welcome',
        text: '<h3>Welcome to Your Donor Portal! 👋</h3><p>This quick tour will help you navigate your dashboard and make the most of your giving experience.</p>',
        attachTo: {
            element: '[data-tour-step="welcome"]',
            on: 'bottom'
        },
        buttons: [
            {
                text: 'Skip Tour',
                action: function() {
                    return this.cancel();
                },
                classes: 'shepherd-button-secondary'
            },
            {
                text: 'Get Started',
                action: function() {
                    return this.next();
                },
                classes: 'shepherd-button-primary'
            }
        ]
    });

    // Step 2: Progress Bar (if exists)
    const progressElement = document.querySelector('[data-tour-step="progress"]');
    if (progressElement) {
        tour.addStep({
            id: 'progress',
            text: '<h3>Track Your Pledge Progress 📊</h3><p>Here you can see how much you\'ve paid towards your pledge goal. The progress bar shows your completion percentage, and you can see your remaining balance at a glance.</p>',
            attachTo: {
                element: '[data-tour-step="progress"]',
                on: 'top'
            },
            buttons: [
                {
                    text: 'Previous',
                    action: function() {
                        return this.back();
                    },
                    classes: 'shepherd-button-secondary'
                },
                {
                    text: 'Next',
                    action: function() {
                        return this.next();
                    },
                    classes: 'shepherd-button-primary'
                }
            ]
        });
    }

    // Step 3: Quick Actions
    tour.addStep({
        id: 'quick-actions',
        text: '<h3>Quick Actions ⚡</h3><p>These buttons give you quick access to the most important features:</p><ul style="text-align: left; margin-top: 10px;"><li><strong>Make Payment</strong> - Submit a new payment</li><li><strong>Payment History</strong> - View all your past payments</li><li><strong>My Plan</strong> - See your payment schedule</li><li><strong>Increase Pledge</strong> - Update your pledge amount</li><li><strong>Contact Us</strong> - Get help or ask questions</li><li><strong>My Profile</strong> - Update your preferences</li></ul>',
        attachTo: {
            element: '[data-tour-step="quick-actions"]',
            on: 'top'
        },
        buttons: [
            {
                text: 'Previous',
                action: function() {
                    return this.back();
                },
                classes: 'shepherd-button-secondary'
            },
            {
                text: 'Next',
                action: function() {
                    return this.next();
                },
                classes: 'shepherd-button-primary'
            }
        ]
    });

    // Step 4: Quick Stats
    tour.addStep({
        id: 'stats',
        text: '<h3>Your Financial Summary 💰</h3><p>These cards show your key financial information at a glance:</p><ul style="text-align: left; margin-top: 10px;"><li><strong>Total Pledged</strong> - Your commitment amount</li><li><strong>Total Paid</strong> - Amount you\'ve contributed so far</li><li><strong>Remaining Balance</strong> - What\'s left to pay</li><li><strong>Payments Made</strong> - Number of payments completed</li></ul>',
        attachTo: {
            element: '[data-tour-step="stats"]',
            on: 'top'
        },
        buttons: [
            {
                text: 'Previous',
                action: function() {
                    return this.back();
                },
                classes: 'shepherd-button-secondary'
            },
            {
                text: 'Next',
                action: function() {
                    return this.next();
                },
                classes: 'shepherd-button-primary'
            }
        ]
    });

    // Step 5: Payment Plan (if exists)
    const paymentPlanElement = document.querySelector('[data-tour-step="payment-plan"]');
    if (paymentPlanElement) {
        tour.addStep({
            id: 'payment-plan',
            text: '<h3>Your Payment Plan 📅</h3><p>If you have an active payment plan, you\'ll see details here including your monthly amount, next payment due date, and progress. You can view the full schedule or make a payment directly from this card.</p>',
            attachTo: {
                element: '[data-tour-step="payment-plan"]',
                on: 'top'
            },
            buttons: [
                {
                    text: 'Previous',
                    action: function() {
                        return this.back();
                    },
                    classes: 'shepherd-button-secondary'
                },
                {
                    text: 'Next',
                    action: function() {
                        return this.next();
                    },
                    classes: 'shepherd-button-primary'
                }
            ]
        });
    }

    // Step 6: Navigation Sidebar
    const sidebarToggle = document.getElementById('sidebarToggle');
    if (sidebarToggle) {
        tour.addStep({
            id: 'navigation',
            text: '<h3>Navigation Menu 📱</h3><p>Use the menu button (☰) in the top-left corner to access all portal features. The sidebar contains organized sections for payments, your account, and more. You can always come back here to navigate between different pages.</p>',
            attachTo: {
                element: sidebarToggle,
                on: 'bottom'
            },
            buttons: [
                {
                    text: 'Previous',
                    action: function() {
                        return this.back();
                    },
                    classes: 'shepherd-button-secondary'
                },
                {
                    text: 'Finish',
                    action: function() {
                        localStorage.setItem('donor-portal-tour-completed', 'true');
                        return this.complete();
                    },
                    classes: 'shepherd-button-primary'
                }
            ]
        });
    } else {
        // Fallback if sidebar toggle doesn't exist
        tour.addStep({
            id: 'navigation',
            text: '<h3>You\'re All Set! 🎉</h3><p>You now know how to navigate your donor portal. Use the menu to access all features, track your progress, and manage your payments. Thank you for your generosity!</p>',
            buttons: [
                {
                    text: 'Previous',
                    action: function() {
                        return this.back();
                    },
                    classes: 'shepherd-button-secondary'
                },
                {
                    text: 'Finish',
                    action: function() {
                        localStorage.setItem('donor-portal-tour-completed', 'true');
                        return this.complete();
                    },
                    classes: 'shepherd-button-primary'
                }
            ]
        });
    }

    // Start the tour after a short delay to ensure page is fully loaded
    setTimeout(() => {
        tour.start();
    }, 500);

    // Handle tour cancellation
    tour.on('cancel', () => {
        localStorage.setItem('donor-portal-tour-completed', 'true');
    });

    // Handle tour completion
    tour.on('complete', () => {
        localStorage.setItem('donor-portal-tour-completed', 'true');
    });
}
