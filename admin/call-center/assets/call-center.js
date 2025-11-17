// Call Center Enhanced JavaScript - Mobile First

// ===== Auto Refresh Functionality =====
let refreshInterval;
let refreshCountdown = 120; // 2 minutes
let isRefreshing = false;

function initAutoRefresh() {
    // Clear any existing interval
    if (refreshInterval) {
        clearInterval(refreshInterval);
    }
    
    // Set up countdown
    refreshInterval = setInterval(() => {
        refreshCountdown--;
        
        if (refreshCountdown <= 10 && !isRefreshing) {
            showRefreshIndicator(refreshCountdown);
        }
        
        if (refreshCountdown <= 0) {
            refreshQueue();
        }
    }, 1000);
}

function showRefreshIndicator(seconds) {
    let indicator = document.querySelector('.refresh-indicator');
    if (!indicator) {
        indicator = document.createElement('div');
        indicator.className = 'refresh-indicator';
        indicator.innerHTML = `
            <i class="fas fa-sync-alt fa-spin"></i>
            <span>Refreshing in <span class="countdown">${seconds}</span>s...</span>
        `;
        document.body.appendChild(indicator);
    }
    
    indicator.classList.add('show');
    const countdownSpan = indicator.querySelector('.countdown');
    if (countdownSpan) {
        countdownSpan.textContent = seconds;
    }
}

function refreshQueue() {
    if (isRefreshing) return;
    
    isRefreshing = true;
    refreshCountdown = 120; // Reset countdown
    
    // Show loading on queue table
    const queueCard = document.querySelector('.card:has(.fa-list-check)');
    if (queueCard) {
        const loadingOverlay = document.createElement('div');
        loadingOverlay.className = 'loading-overlay';
        loadingOverlay.innerHTML = '<div class="spinner-border text-primary"></div>';
        queueCard.style.position = 'relative';
        queueCard.appendChild(loadingOverlay);
    }
    
    // Reload the page
    setTimeout(() => {
        location.reload();
    }, 500);
}

// ===== Mobile Table Enhancement =====
function enhanceMobileTables() {
    if (window.innerWidth <= 767) {
        const tables = document.querySelectorAll('.table');
        tables.forEach(table => {
            const headers = Array.from(table.querySelectorAll('thead th')).map(th => th.textContent.trim());
            
            table.querySelectorAll('tbody tr').forEach(row => {
                const cells = row.querySelectorAll('td');
                cells.forEach((cell, index) => {
                    if (headers[index] && !cell.hasAttribute('data-label')) {
                        cell.setAttribute('data-label', headers[index]);
                    }
                });
            });
        });
    }
}

// ===== Phone Number Click Tracking =====
function trackPhoneClicks() {
    document.querySelectorAll('.phone-link').forEach(link => {
        link.addEventListener('click', function(e) {
            const phone = this.textContent.trim();
            const donorName = this.closest('tr')?.querySelector('.donor-name')?.textContent || 'Unknown';
            
            // Log to console (in production, send to analytics)
            console.log('Phone clicked:', { phone, donorName, timestamp: new Date() });
            
            // Visual feedback
            this.style.color = '#10b981';
            setTimeout(() => {
                this.style.color = '';
            }, 2000);
        });
    });
}

// ===== Priority Visual Enhancement =====
function enhancePriorityBadges() {
    document.querySelectorAll('.priority-badge').forEach(badge => {
        const priority = parseInt(badge.textContent);
        if (priority >= 9) {
            badge.innerHTML = `<i class="fas fa-fire me-1"></i>${priority}`;
        } else if (priority >= 7) {
            badge.innerHTML = `<i class="fas fa-exclamation me-1"></i>${priority}`;
        }
    });
}

// ===== Time Formatting =====
function formatTimeAgo(dateString) {
    const date = new Date(dateString);
    const now = new Date();
    const seconds = Math.floor((now - date) / 1000);
    
    if (seconds < 60) return 'Just now';
    if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
    if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
    return `${Math.floor(seconds / 86400)}d ago`;
}

// ===== Stats Animation =====
function animateStats() {
    document.querySelectorAll('.stat-value').forEach(stat => {
        const finalValue = stat.textContent;
        const isPercentage = finalValue.includes('%');
        const isTime = finalValue.includes(':');
        
        if (!isPercentage && !isTime) {
            const numericValue = parseInt(finalValue) || 0;
            let currentValue = 0;
            const increment = numericValue / 20;
            
            const counter = setInterval(() => {
                currentValue += increment;
                if (currentValue >= numericValue) {
                    currentValue = numericValue;
                    clearInterval(counter);
                }
                stat.textContent = Math.round(currentValue);
            }, 50);
        }
    });
}

// ===== Swipe to Call (Mobile) =====
function enableSwipeToCall() {
    if ('ontouchstart' in window) {
        let startX = 0;
        let currentX = 0;
        let card = null;
        
        document.querySelectorAll('.table tbody tr').forEach(row => {
            row.addEventListener('touchstart', handleTouchStart, { passive: true });
            row.addEventListener('touchmove', handleTouchMove, { passive: true });
            row.addEventListener('touchend', handleTouchEnd);
        });
        
        function handleTouchStart(e) {
            startX = e.touches[0].clientX;
            card = e.currentTarget;
        }
        
        function handleTouchMove(e) {
            currentX = e.touches[0].clientX;
            const diff = startX - currentX;
            
            if (diff > 50) {
                card.style.transform = `translateX(-${Math.min(diff, 100)}px)`;
                card.style.background = '#f0fdf4';
            }
        }
        
        function handleTouchEnd(e) {
            const diff = startX - currentX;
            
            if (diff > 100) {
                const callBtn = card.querySelector('.btn-success');
                if (callBtn) {
                    callBtn.click();
                }
            }
            
            card.style.transform = '';
            card.style.background = '';
            card = null;
        }
    }
}

// ===== Keyboard Shortcuts =====
function initKeyboardShortcuts() {
    document.addEventListener('keydown', (e) => {
        // R = Refresh
        if (e.key === 'r' && e.altKey) {
            e.preventDefault();
            refreshQueue();
        }
        
        // 1-9 = Quick dial first 9 donors
        if (e.key >= '1' && e.key <= '9' && e.altKey) {
            e.preventDefault();
            const index = parseInt(e.key) - 1;
            const callBtns = document.querySelectorAll('.btn-success');
            if (callBtns[index]) {
                callBtns[index].click();
            }
        }
    });
}

// ===== Quick Actions Menu (Mobile) =====
function createQuickActionsMenu() {
    if (window.innerWidth <= 767) {
        const menu = document.createElement('div');
        menu.className = 'quick-actions-menu';
        menu.innerHTML = `
            <button class="quick-action" onclick="refreshQueue()">
                <i class="fas fa-sync-alt"></i>
                <span>Refresh</span>
            </button>
            <button class="quick-action" onclick="scrollToTop()">
                <i class="fas fa-arrow-up"></i>
                <span>Top</span>
            </button>
        `;
        menu.style.cssText = `
            position: fixed;
            bottom: 80px;
            right: 20px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            z-index: 1000;
        `;
        document.body.appendChild(menu);
    }
}

function scrollToTop() {
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// ===== Initialize Everything =====
document.addEventListener('DOMContentLoaded', function() {
    // Initialize auto-refresh
    initAutoRefresh();
    
    // Enhance mobile tables
    enhanceMobileTables();
    
    // Track phone clicks
    trackPhoneClicks();
    
    // Enhance priority badges
    enhancePriorityBadges();
    
    // Animate stats on load
    animateStats();
    
    // Enable swipe to call on mobile
    enableSwipeToCall();
    
    // Initialize keyboard shortcuts
    initKeyboardShortcuts();
    
    // Create quick actions menu for mobile
    createQuickActionsMenu();
    
    // Handle window resize
    let resizeTimer;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => {
            enhanceMobileTables();
            
            // Remove/add quick actions based on screen size
            const existingMenu = document.querySelector('.quick-actions-menu');
            if (existingMenu) {
                existingMenu.remove();
            }
            createQuickActionsMenu();
        }, 250);
    });
    
    // Show keyboard shortcuts on desktop
    if (window.innerWidth > 767) {
        console.log(`
🎹 Call Center Keyboard Shortcuts:
━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Alt + R → Refresh Queue
Alt + 1-9 → Quick dial donor 1-9
━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        `);
    }
});

// ===== Utility Functions =====
function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `toast-notification toast-${type}`;
    toast.textContent = message;
    toast.style.cssText = `
        position: fixed;
        bottom: 20px;
        left: 50%;
        transform: translateX(-50%);
        background: ${type === 'success' ? '#10b981' : '#ef4444'};
        color: white;
        padding: 12px 24px;
        border-radius: 50px;
        font-weight: 500;
        z-index: 9999;
        animation: slideUp 0.3s ease-out;
    `;
    
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.style.animation = 'slideDown 0.3s ease-out';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// Add CSS animations
const style = document.createElement('style');
style.textContent = `
    @keyframes slideUp {
        from { transform: translate(-50%, 100%); opacity: 0; }
        to { transform: translate(-50%, 0); opacity: 1; }
    }
    @keyframes slideDown {
        from { transform: translate(-50%, 0); opacity: 1; }
        to { transform: translate(-50%, 100%); opacity: 0; }
    }
    
    .quick-action {
        background: #2563eb;
        color: white;
        border: none;
        border-radius: 50%;
        width: 56px;
        height: 56px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        cursor: pointer;
        transition: all 0.3s;
    }
    
    .quick-action:hover {
        background: #1d4ed8;
        transform: scale(1.1);
    }
    
    .quick-action i {
        font-size: 1.25rem;
        margin-bottom: 2px;
    }
    
    .quick-action span {
        font-size: 0.625rem;
        font-weight: 500;
    }
`;
document.head.appendChild(style);

// Export functions for global access
window.refreshQueue = refreshQueue;
window.showToast = showToast;