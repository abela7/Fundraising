// Donor Portal JavaScript - Mobile First - Matching Registrar Portal

// ============ PWA Support ============
(function() {
  // Add manifest link
  if (!document.querySelector('link[rel="manifest"]')) {
    const link = document.createElement('link');
    link.rel = 'manifest';
    link.href = '/donor/manifest.json';
    document.head.appendChild(link);
  }
  
  // Add theme-color
  if (!document.querySelector('meta[name="theme-color"]')) {
    const meta = document.createElement('meta');
    meta.name = 'theme-color';
    meta.content = '#0a6286';
    document.head.appendChild(meta);
  }
  
  // Add apple meta tags
  if (!document.querySelector('meta[name="apple-mobile-web-app-capable"]')) {
    const meta = document.createElement('meta');
    meta.name = 'apple-mobile-web-app-capable';
    meta.content = 'yes';
    document.head.appendChild(meta);
  }
  
  // Register Service Worker
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
      navigator.serviceWorker.register('/donor/sw.js')
        .then(function(reg) { console.log('[PWA] Donor SW registered'); })
        .catch(function(err) { console.log('[PWA] SW failed:', err); });
    });
  }
  
  // PWA Install handling
  let deferredPrompt = null;
  
  window.addEventListener('beforeinstallprompt', function(e) {
    e.preventDefault();
    deferredPrompt = e;
    console.log('[PWA] Install prompt available');
    showInstallButton();
  });
  
  window.addEventListener('appinstalled', function() {
    console.log('[PWA] App installed!');
    hideInstallButton();
  });
  
  function isAppInstalled() {
    return window.matchMedia('(display-mode: standalone)').matches || 
           window.navigator.standalone === true;
  }
  
  function showInstallButton() {
    let btn = document.getElementById('pwaInstallBtn');
    if (!btn && !isAppInstalled()) {
      createInstallButton();
    }
  }
  
  function hideInstallButton() {
    const btn = document.getElementById('pwaInstallBtn');
    if (btn) btn.style.display = 'none';
  }
  
  function createInstallButton() {
    if (isAppInstalled()) return;
    
    const btn = document.createElement('button');
    btn.id = 'pwaInstallBtn';
    btn.className = 'pwa-install-fab';
    btn.innerHTML = '<i class="fas fa-download"></i> Install App';
    btn.title = 'Install App';
    btn.onclick = installPWA;
    
    btn.style.cssText = `
      position: fixed;
      bottom: 20px;
      right: 20px;
      z-index: 9999;
      padding: 12px 20px;
      background: linear-gradient(135deg, #0a6286 0%, #084a66 100%);
      color: white;
      border: none;
      border-radius: 50px;
      font-size: 14px;
      font-weight: 600;
      cursor: pointer;
      box-shadow: 0 4px 15px rgba(10, 98, 134, 0.4);
      display: flex;
      align-items: center;
      gap: 8px;
      animation: pulse 2s infinite;
    `;
    
    if (!document.getElementById('pwa-fab-styles')) {
      const style = document.createElement('style');
      style.id = 'pwa-fab-styles';
      style.textContent = `
        @keyframes pulse {
          0%, 100% { box-shadow: 0 4px 15px rgba(10, 98, 134, 0.4); }
          50% { box-shadow: 0 4px 25px rgba(10, 98, 134, 0.6); }
        }
        .pwa-install-fab:hover {
          transform: translateY(-2px);
          box-shadow: 0 6px 20px rgba(10, 98, 134, 0.5) !important;
        }
        @media (display-mode: standalone) {
          .pwa-install-fab { display: none !important; }
        }
      `;
      document.head.appendChild(style);
    }
    
    document.body.appendChild(btn);
  }
  
  window.installPWA = async function() {
    if (deferredPrompt) {
      deferredPrompt.prompt();
      const { outcome } = await deferredPrompt.userChoice;
      if (outcome === 'accepted') {
        alert('App installed successfully! You can now access it from your home screen.');
      }
      deferredPrompt = null;
      hideInstallButton();
    } else if (/iPad|iPhone|iPod/.test(navigator.userAgent)) {
      alert('To install on iOS:\n\n1. Tap the Share button (⬆️)\n2. Tap "Add to Home Screen"\n3. Tap "Add"');
    } else {
      alert('To install:\n\n1. Open browser menu (⋮)\n2. Tap "Install App" or "Add to Home Screen"');
    }
  };
  
  document.addEventListener('DOMContentLoaded', function() {
    if (!isAppInstalled()) {
      setTimeout(createInstallButton, 2000);
    }
  });
})();
// ============ End PWA Support ============

// DOM ready
document.addEventListener('DOMContentLoaded', function() {
    console.log('✅ DOM loaded');
    
    // Initialize components
    initSidebar();
    initTooltips();
    
    // Initialize tour if this is first login
    if (window.showDonorTour) {
        console.log('🎯 showDonorTour flag is true, initializing tour...');
        initDonorTour();
    } else {
        console.log('ℹ️ showDonorTour flag is false, skipping tour');
    }
    
    // Add helper function to window for manual tour trigger (testing)
    window.startDonorTour = function() {
        console.log('🔧 Manual tour trigger');
        localStorage.removeItem('donor-portal-tour-completed');
        localStorage.removeItem('donor-portal-tour-started');
        initDonorTour();
    };
    
    // Log for debugging
    console.log('💡 To manually start tour, run: startDonorTour()');
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
function getDriverFactory() {
    // Different Driver.js builds expose different globals.
    // We support the common IIFE exports here.
    if (typeof window.driver === 'function') return window.driver;
    if (window.driverjs && typeof window.driverjs.driver === 'function') return window.driverjs.driver;
    if (window.driver && typeof window.driver === 'object' && typeof window.driver.driver === 'function') return window.driver.driver;

    return null;
}

function initDonorTour() {
    // Check if Driver.js is available
    if (!getDriverFactory()) {
        console.error('Driver.js not loaded (no driver() factory). Tour cannot be initialized.');
        // Try again after a delay
        setTimeout(function() {
            if (getDriverFactory()) {
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

    // Validate element exists
    const welcomeEl = document.querySelector('[data-tour-step="welcome"]');
    console.log('Welcome element found:', !!welcomeEl);
    
    if (!welcomeEl) {
        console.error('❌ Welcome element not found! Tour cannot start.');
        alert('Tour setup error: Welcome element not found. Please refresh the page.');
        return;
    }

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
    const progressEl = document.querySelector('[data-tour-step="progress"]');
    console.log('Progress element found:', !!progressEl);
    if (progressEl) {
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
    const quickActionsEl = document.querySelector('[data-tour-step="quick-actions"]');
    console.log('Quick Actions element found:', !!quickActionsEl);
    if (quickActionsEl) {
        steps.push({
            element: '[data-tour-step="quick-actions"]',
            popover: {
                title: '⚡ Quick Actions',
                description: '<div style="text-align: left;"><p><strong>Everything you need in one place:</strong></p><ul style="margin: 10px 0; padding-left: 20px;"><li><strong>Make Payment</strong> - Submit a payment instantly</li><li><strong>Payment History</strong> - View all past payments</li><li><strong>My Plan</strong> - Check your payment schedule</li><li><strong>Update Pledge</strong> - Increase your commitment</li><li><strong>Contact Us</strong> - Get help anytime</li></ul></div>',
                side: 'top',
                align: 'center'
            }
        });
    }

    // Step 4: Quick Stats
    const statsEl = document.querySelector('[data-tour-step="stats"]');
    console.log('Stats element found:', !!statsEl);
    if (statsEl) {
        steps.push({
            element: '[data-tour-step="stats"]',
            popover: {
                title: '💰 Your Financial Summary',
                description: '<div style="text-align: left;"><p>All your important numbers at a glance:</p><ul style="margin: 10px 0; padding-left: 20px;"><li><strong>Total Pledged</strong> - Your commitment</li><li><strong>Total Paid</strong> - What you\'ve given</li><li><strong>Balance</strong> - What remains</li><li><strong>Payments Made</strong> - Your progress</li></ul></div>',
                side: 'top',
                align: 'center'
            }
        });
    }

    // Step 5: Payment Plan (if exists)
    const paymentPlanEl = document.querySelector('[data-tour-step="payment-plan"]');
    console.log('Payment Plan element found:', !!paymentPlanEl);
    if (paymentPlanEl) {
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
    console.log('Sidebar toggle element found:', !!sidebarToggle);
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

    console.log('✅ Total tour steps prepared:', steps.length);
    
    if (steps.length < 2) {
        console.error('❌ Not enough tour steps! Need at least 2, found:', steps.length);
        alert('Tour setup incomplete. Please refresh the page.');
        return;
    }

    // Initialize Driver.js with modern, mobile-friendly settings
    console.log('Initializing Driver.js object...');
    const driverFactory = getDriverFactory();
    console.log('Driver factory type:', typeof driverFactory);
    let driverObj;
    try {
        driverObj = driverFactory({
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
                if (driverObj) driverObj.destroy();
            },
            onDestroyed: () => {
                // Mark tour as completed
                localStorage.setItem('donor-portal-tour-completed', 'true');
                console.log('✅ Tour completed!');
            }
        });
        console.log('✅ Driver.js object created successfully');
    } catch (error) {
        console.error('❌ Error creating Driver.js object:', error);
        alert('Failed to initialize tour: ' + error.message);
        return;
    }

    // Start the tour after ensuring page is fully loaded
    setTimeout(() => {
        console.log('🚀 Starting tour with ' + steps.length + ' steps');
        console.log('Tour steps:', steps);
        try {
            if (!driverObj) {
                console.error('Driver object not created!');
                return;
            }
            console.log('Driver object ready, calling drive()...');
            localStorage.setItem('donor-portal-tour-started', 'true');
            driverObj.drive();
            console.log('Tour started successfully!');
        } catch (error) {
            console.error('Error starting tour:', error);
            console.error('Error stack:', error.stack);
            alert('Tour error: ' + error.message + '\n\nPlease refresh the page and try again.');
            localStorage.removeItem('donor-portal-tour-started');
        }
    }, 1800);
}
