// Simple Message Notifications
(function() {
    'use strict';
    
    let checkInterval;
    
    function checkUnreadMessages() {
        // Determine the correct API path based on current location
        let apiPath;
        if (window.location.pathname.includes('/admin/')) {
            apiPath = '/fundraising/admin/messages/?action=unread-count';
        } else if (window.location.pathname.includes('/registrar/')) {
            apiPath = '/fundraising/registrar/messages/?action=conversations';
        } else {
            return; // Not on admin or registrar pages
        }
        
        fetch(apiPath, { credentials: 'same-origin' })
            .then(response => response.json())
            .then(data => {
                let unreadCount = 0;
                
                if (data.unread_count !== undefined) {
                    // Admin response
                    unreadCount = data.unread_count;
                } else if (data.conversations) {
                    // Registrar response - count unread from conversations
                    unreadCount = data.conversations.reduce((total, conv) => total + conv.unread, 0);
                }
                
                updateNotificationBadge(unreadCount);
            })
            .catch(error => {
                console.log('Message notification check failed:', error);
            });
    }
    
    function updateNotificationBadge(count) {
        const notification = document.getElementById('messageNotification');
        const countElement = document.getElementById('messageCount');
        
        if (notification && countElement) {
            if (count > 0) {
                countElement.textContent = count > 99 ? '99+' : count;
                notification.style.display = 'block';
            } else {
                notification.style.display = 'none';
            }
        }
    }
    
    function startNotifications() {
        // Check immediately
        checkUnreadMessages();
        
        // Check every 30 seconds
        checkInterval = setInterval(checkUnreadMessages, 30000);
    }
    
    function stopNotifications() {
        if (checkInterval) {
            clearInterval(checkInterval);
            checkInterval = null;
        }
    }
    
    // Start when page loads
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', startNotifications);
    } else {
        startNotifications();
    }
    
    // Stop when page unloads
    window.addEventListener('beforeunload', stopNotifications);
    
})();
