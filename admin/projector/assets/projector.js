// Enhanced Projector Control JavaScript

// State Management
const projectorState = {
    connected: false,
    settings: {
        refreshRate: 10,
        displayTheme: 'default',
        showTicker: true,
        showProgress: true,
        showQR: true,
        showClock: true
    },
    stats: {
        activeViewers: 0,
        uptime: 0,
        messagesSent: 0,
        effectsTriggered: 0
    },
    startTime: Date.now()
};

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    initializeControls();
    setupEventListeners();
    startStatUpdates();
    checkPreviewConnection();
});

// Initialize Controls
function initializeControls() {
    // Load saved settings from localStorage
    const savedSettings = localStorage.getItem('projectorSettings');
    if (savedSettings) {
        projectorState.settings = { ...projectorState.settings, ...JSON.parse(savedSettings) };
        
        // Apply saved settings to controls
        document.getElementById('refreshRate').value = projectorState.settings.refreshRate;
        document.getElementById('displayTheme').value = projectorState.settings.displayTheme;
        document.getElementById('showTicker').checked = projectorState.settings.showTicker;
        document.getElementById('showProgress').checked = projectorState.settings.showProgress;
        document.getElementById('showQR').checked = projectorState.settings.showQR;
        document.getElementById('showClock').checked = projectorState.settings.showClock;
    }
}

// Setup Event Listeners
function setupEventListeners() {
    // Settings changes
    document.getElementById('refreshRate').addEventListener('change', updateSettings);
    document.getElementById('displayTheme').addEventListener('change', updateSettings);
    document.getElementById('showTicker').addEventListener('change', updateSettings);
    document.getElementById('showProgress').addEventListener('change', updateSettings);
    document.getElementById('showQR').addEventListener('change', updateSettings);
    document.getElementById('showClock').addEventListener('change', updateSettings);
    
    // Announcement form
    const announcementForm = document.getElementById('announcementForm');
    if (announcementForm) {
        announcementForm.addEventListener('submit', handleAnnouncementSubmit);
    }
    
    // Footer message form
    const footerMessageForm = document.getElementById('footerMessageForm');
    if (footerMessageForm) {
        footerMessageForm.addEventListener('submit', handleFooterMessageSubmit);
    }
}

// Update Settings
function updateSettings() {
    projectorState.settings = {
        refreshRate: parseInt(document.getElementById('refreshRate').value),
        displayTheme: document.getElementById('displayTheme').value,
        showTicker: document.getElementById('showTicker').checked,
        showProgress: document.getElementById('showProgress').checked,
        showQR: document.getElementById('showQR').checked,
        showClock: document.getElementById('showClock').checked
    };
    
    // Save to localStorage
    localStorage.setItem('projectorSettings', JSON.stringify(projectorState.settings));
}

// Open Projector View
function openProjectorView() {
    const url = `/fundraising/public/projector/`;
    
    // Open in new window with fullscreen
    const projectorWindow = window.open(url, 'projectorView', 'fullscreen=yes');
    
    // Try to make it fullscreen
    if (projectorWindow) {
        projectorWindow.moveTo(0, 0);
        projectorWindow.resizeTo(screen.width, screen.height);
        
        // Show success notification
        showNotification('Projector view opened in new window', 'success');
    }
}

// Refresh Preview
function refreshPreview() {
    const iframe = document.getElementById('previewFrame');
    const btn = event.target.closest('button');
    
    // Add spinning animation
    const icon = btn.querySelector('i');
    icon.classList.add('fa-spin');
    
    // Show loading overlay
    const overlay = document.getElementById('previewOverlay');
    overlay.classList.remove('show');
    
    // Reload iframe
    iframe.src = iframe.src;
    
    // Remove spinning and show connected after delay
    setTimeout(() => {
        icon.classList.remove('fa-spin');
        checkPreviewConnection();
    }, 1000);
}

// Check Preview Connection
function checkPreviewConnection() {
    const overlay = document.getElementById('previewOverlay');
    const iframe = document.getElementById('previewFrame');
    
    // Simulate connection check
    setTimeout(() => {
        overlay.classList.add('show');
        projectorState.connected = true;
        
        // Update connection status
        const statusIndicator = document.querySelector('.status-indicator');
        if (statusIndicator) {
            statusIndicator.classList.add('active');
            statusIndicator.innerHTML = '<i class="fas fa-circle"></i> Active';
        }
    }, 500);
}

// Apply Settings
function applySettings() {
    updateSettings();
    
    const btn = event.target;
    const originalText = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Applying...';
    btn.disabled = true;
    
    // Send settings to projector
    sendCommand('updateSettings', projectorState.settings);
    
    setTimeout(() => {
        btn.innerHTML = originalText;
        btn.disabled = false;
        showNotification('Settings applied successfully!', 'success');
        refreshPreview();
    }, 1500);
}

// Trigger Effect
function triggerEffect(effect) {
    const btn = event.target.closest('button');
    
    // Increment effect counter
    projectorState.stats.effectsTriggered++;
    updateStatDisplay('effectsTriggered', projectorState.stats.effectsTriggered);
    
    // Disable button temporarily
    btn.disabled = true;
    btn.style.transform = 'scale(0.95)';
    
    // Send effect command
    sendCommand('triggerEffect', { effect });
    
    // Show effect in preview
    showPreviewEffect(effect);
    
    setTimeout(() => {
        btn.style.transform = 'scale(1)';
        btn.disabled = false;
    }, 300);
    
    showNotification(`${effect.charAt(0).toUpperCase() + effect.slice(1)} effect triggered!`, 'info');
}

// Show Preview Effect
function showPreviewEffect(effect) {
    const iframe = document.getElementById('previewFrame');
    
    // Send message to iframe
    if (iframe && iframe.contentWindow) {
        iframe.contentWindow.postMessage({
            type: 'effect',
            effect: effect
        }, '*');
    }
}

// Send Preset Message
function sendPreset(message) {
    document.getElementById('messageText').value = message;
    document.getElementById('messageType').value = 'success';
    document.getElementById('duration').value = '10';
    
    // Auto submit form
    document.getElementById('announcementForm').dispatchEvent(new Event('submit'));
}

// Handle Announcement Submit
function handleAnnouncementSubmit(e) {
    e.preventDefault();
    
    const messageType = document.getElementById('messageType').value;
    const messageText = document.getElementById('messageText').value;
    const duration = document.getElementById('duration').value;
    
    if (!messageText.trim()) {
        showNotification('Please enter a message', 'warning');
        return;
    }
    
    // Increment message counter
    projectorState.stats.messagesSent++;
    updateStatDisplay('messagesSent', projectorState.stats.messagesSent);
    
    // Show loading on button
    const btn = this.querySelector('button[type="submit"]');
    const originalText = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Sending...';
    btn.disabled = true;
    
    // Send announcement
    sendCommand('announcement', {
        type: messageType,
        text: messageText,
        duration: parseInt(duration) * 1000
    });
    
    // Show announcement in preview
    showPreviewAnnouncement(messageText, messageType);
    
    setTimeout(() => {
        btn.innerHTML = originalText;
        btn.disabled = false;
        this.reset();
        showNotification('Announcement sent to projector!', 'success');
    }, 1500);
}

// Show Preview Announcement
function showPreviewAnnouncement(text, type) {
    const iframe = document.getElementById('previewFrame');
    
    // Send message to iframe
    if (iframe && iframe.contentWindow) {
        iframe.contentWindow.postMessage({
            type: 'announcement',
            text: text,
            messageType: type
        }, '*');
    }
}

// Send Command to Projector
function sendCommand(command, data) {
    // In a real implementation, this would use WebSocket or Server-Sent Events
    // For now, we'll use localStorage as a simple message passing mechanism
    const message = {
        command: command,
        data: data,
        timestamp: Date.now()
    };
    
    localStorage.setItem('projectorCommand', JSON.stringify(message));
    
    // Also send via postMessage to preview iframe
    const iframe = document.getElementById('previewFrame');
    if (iframe && iframe.contentWindow) {
        iframe.contentWindow.postMessage(message, '*');
    }
}

// Copy Link
function copyLink() {
    const input = document.getElementById('projectorLink');
    input.select();
    document.execCommand('copy');
    
    const btn = event.target.closest('button');
    const originalHTML = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-check"></i>';
    
    setTimeout(() => {
        btn.innerHTML = originalHTML;
    }, 2000);
    
    showNotification('Link copied to clipboard!', 'success');
}

// Generate QR Code
function generateQR() {
    const link = document.getElementById('projectorLink').value;
    
    // In a real implementation, this would generate an actual QR code
    showNotification('QR code generation would open a modal with the QR code', 'info');
}

// Open Fullscreen
function openFullscreen() {
    openProjectorView();
}

// Handle Footer Message Submit
function handleFooterMessageSubmit(e) {
    e.preventDefault();
    
    const footerText = document.getElementById('footerText').value;
    
    if (!footerText.trim()) {
        showNotification('Please enter footer text', 'warning');
        return;
    }
    
    // Show loading on button
    const btn = this.querySelector('button[type="submit"]');
    const originalText = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Updating...';
    btn.disabled = true;
    
    // Send footer message
    sendCommand('footerMessage', { text: footerText });
    
    // Also send via localStorage for immediate update
    localStorage.setItem('projectorFooterMessage', JSON.stringify({
        text: footerText,
        timestamp: Date.now()
    }));
    
    // Show footer in preview
    const iframe = document.getElementById('previewFrame');
    if (iframe && iframe.contentWindow) {
        iframe.contentWindow.postMessage({
            type: 'footerMessage',
            text: footerText
        }, '*');
    }
    
    setTimeout(() => {
        btn.innerHTML = originalText;
        btn.disabled = false;
        showNotification('Footer message updated!', 'success');
    }, 1000);
}

// Update Stat Display
function updateStatDisplay(stat, value) {
    const element = document.getElementById(stat);
    if (element) {
        element.textContent = value;
        
        // Add pulse animation
        element.classList.add('pulse');
        setTimeout(() => element.classList.remove('pulse'), 500);
    }
}

// Start Stat Updates
function startStatUpdates() {
    // Update uptime every second
    setInterval(() => {
        const uptime = Math.floor((Date.now() - projectorState.startTime) / 1000);
        const hours = Math.floor(uptime / 3600);
        const minutes = Math.floor((uptime % 3600) / 60);
        const seconds = uptime % 60;
        
        const uptimeStr = `${hours}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
        updateStatDisplay('uptime', uptimeStr);
    }, 1000);
    
    // Simulate active viewers
    setInterval(() => {
        const viewers = Math.floor(Math.random() * 5) + 1;
        updateStatDisplay('activeViewers', viewers);
    }, 10000);
    
    // Update time every second
    setInterval(() => {
        const timeElement = document.querySelector('.status-time');
        if (timeElement) {
            timeElement.textContent = new Date().toLocaleTimeString();
        }
    }, 1000);
}

// Show Notification
function showNotification(message, type = 'info') {
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    
    const icons = {
        success: 'check-circle',
        info: 'info-circle',
        warning: 'exclamation-circle',
        error: 'times-circle'
    };
    
    notification.innerHTML = `
        <i class="fas fa-${icons[type] || 'info-circle'} me-2"></i>
        ${message}
    `;
    
    // Add to body
    document.body.appendChild(notification);
    
    // Trigger animation
    setTimeout(() => {
        notification.classList.add('show');
    }, 10);
    
    // Remove after delay
    setTimeout(() => {
        notification.classList.remove('show');
        setTimeout(() => {
            notification.remove();
        }, 300);
    }, 3000);
}

// Add notification styles
const style = document.createElement('style');
style.textContent = `
.notification {
    position: fixed;
    top: 20px;
    right: 20px;
    padding: 1rem 1.5rem;
    background: var(--glass);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: var(--radius);
    color: var(--text);
    transform: translateX(400px);
    transition: transform 0.3s ease;
    z-index: 9999;
    min-width: 300px;
    max-width: 500px;
}

.notification.show {
    transform: translateX(0);
}

.notification-success {
    border-color: rgba(16, 185, 129, 0.3);
    background: rgba(16, 185, 129, 0.1);
}

.notification-info {
    border-color: rgba(59, 130, 246, 0.3);
    background: rgba(59, 130, 246, 0.1);
}

.notification-warning {
    border-color: rgba(245, 158, 11, 0.3);
    background: rgba(245, 158, 11, 0.1);
}

.notification-error {
    border-color: rgba(239, 68, 68, 0.3);
    background: rgba(239, 68, 68, 0.1);
}

.pulse {
    animation: pulse 0.5s ease-out;
}

@keyframes pulse {
    0% {
        transform: scale(1);
    }
    50% {
        transform: scale(1.1);
    }
    100% {
        transform: scale(1);
    }
}
`;
document.head.appendChild(style);

// Listen for messages from projector
window.addEventListener('message', function(event) {
    // Handle messages from projector iframe
    if (event.data && event.data.type === 'projectorStatus') {
        // Update stats based on projector status
        if (event.data.viewers !== undefined) {
            updateStatDisplay('activeViewers', event.data.viewers);
        }
    }
});