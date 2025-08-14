// Clear message notification badge when on messages page
(function() {
    'use strict';
    
    // Only run on messages pages
    if (!window.location.pathname.includes('/messages/')) {
        return;
    }
    
    function hideBadge() {
        const badge = document.getElementById('messageNotification');
        if (badge) {
            badge.style.display = 'none';
        }
    }
    
    // Hide immediately
    hideBadge();
    
    // Hide again after a short delay to ensure DOM is ready
    setTimeout(hideBadge, 100);
    
    // Also hide when page becomes visible (if user switches tabs)
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            setTimeout(hideBadge, 500);
        }
    });
    
})();
