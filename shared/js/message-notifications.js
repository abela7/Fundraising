// Simple Message Notification Updates
(function() {
    'use strict';
    
    // Only run if not on messages page
    if (window.location.pathname.includes('/messages/')) {
        return;
    }
    
    let lastCount = parseInt(document.getElementById('messageCount')?.textContent || '0');
    
    function checkMessages() {
        // Determine API endpoint based on current location
        let apiUrl;
        if (window.location.pathname.includes('/admin/')) {
            apiUrl = '../../admin/messages/?action=unread-count';
        } else if (window.location.pathname.includes('/registrar/')) {
            apiUrl = './messages/?action=unread-count';
        } else {
            return;
        }
        
        fetch(apiUrl, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            const newCount = data.unread_count || 0;
            updateBadge(newCount);
        })
        .catch(error => {
            console.log('Check messages error:', error);
        });
    }
    
    function updateBadge(count) {
        const badge = document.getElementById('messageNotification');
        const countEl = document.getElementById('messageCount');
        const btn = document.getElementById('messagesBtn');
        
        if (!btn) return;
        
        if (count > 0) {
            // Create or show badge
            if (!badge) {
                const newBadge = document.createElement('span');
                newBadge.className = 'position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger';
                newBadge.id = 'messageNotification';
                newBadge.innerHTML = '<span id="messageCount">' + (count > 99 ? '99+' : count) + '</span>';
                btn.appendChild(newBadge);
                
                // Animate new messages
                if (count > lastCount) {
                    newBadge.style.animation = 'pulse 0.5s ease-in-out';
                }
            } else {
                // Update existing badge
                if (countEl) {
                    countEl.textContent = count > 99 ? '99+' : count;
                    
                    // Animate on new messages
                    if (count > lastCount) {
                        badge.style.animation = 'none';
                        setTimeout(() => {
                            badge.style.animation = 'pulse 0.5s ease-in-out';
                        }, 10);
                    }
                }
                badge.style.display = 'block';
            }
        } else {
            // Hide badge if no messages
            if (badge) {
                badge.style.display = 'none';
            }
        }
        
        lastCount = count;
    }
    
    // Add CSS animation
    if (!document.getElementById('messageNotificationStyles')) {
        const style = document.createElement('style');
        style.id = 'messageNotificationStyles';
        style.textContent = `
            @keyframes pulse {
                0% { transform: translate(50%, -50%) scale(1); }
                50% { transform: translate(50%, -50%) scale(1.2); }
                100% { transform: translate(50%, -50%) scale(1); }
            }
        `;
        document.head.appendChild(style);
    }
    
    // Check every 20 seconds
    setInterval(checkMessages, 20000);
    
    // Check on focus
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            checkMessages();
        }
    });
    
})();
