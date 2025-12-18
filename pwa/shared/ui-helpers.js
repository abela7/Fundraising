/**
 * PWA UI Helpers
 * 
 * Common UI utilities for all PWAs.
 */

/**
 * Show a toast notification
 * @param {string} message
 * @param {string} type - 'success', 'error', 'warning', 'info'
 * @param {number} duration - Duration in ms
 */
function showToast(message, type = 'info', duration = 3000) {
  // Remove existing toasts
  const existing = document.querySelector('.pwa-toast');
  if (existing) {
    existing.remove();
  }

  const toast = document.createElement('div');
  toast.className = `pwa-toast pwa-toast-${type}`;
  toast.innerHTML = `
    <span class="pwa-toast-icon">${getToastIcon(type)}</span>
    <span class="pwa-toast-message">${escapeHtml(message)}</span>
  `;

  document.body.appendChild(toast);

  // Trigger animation
  requestAnimationFrame(() => {
    toast.classList.add('pwa-toast-show');
  });

  // Auto-hide
  setTimeout(() => {
    toast.classList.remove('pwa-toast-show');
    setTimeout(() => toast.remove(), 300);
  }, duration);
}

/**
 * Get icon for toast type
 * @param {string} type
 * @returns {string}
 */
function getToastIcon(type) {
  const icons = {
    success: '✓',
    error: '✕',
    warning: '⚠',
    info: 'ℹ',
  };
  return icons[type] || icons.info;
}

/**
 * Escape HTML to prevent XSS
 * @param {string} text
 * @returns {string}
 */
function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

/**
 * Format currency
 * @param {number} amount
 * @param {string} currency
 * @returns {string}
 */
function formatCurrency(amount, currency = 'GBP') {
  return new Intl.NumberFormat('en-GB', {
    style: 'currency',
    currency: currency,
  }).format(amount);
}

/**
 * Format date
 * @param {string} dateString
 * @param {Object} options
 * @returns {string}
 */
function formatDate(dateString, options = {}) {
  const date = new Date(dateString);
  const defaultOptions = {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    ...options,
  };
  return new Intl.DateTimeFormat('en-GB', defaultOptions).format(date);
}

/**
 * Format relative time (e.g., "2 hours ago")
 * @param {string} dateString
 * @returns {string}
 */
function formatRelativeTime(dateString) {
  const date = new Date(dateString);
  const now = new Date();
  const diff = now - date;
  
  const seconds = Math.floor(diff / 1000);
  const minutes = Math.floor(seconds / 60);
  const hours = Math.floor(minutes / 60);
  const days = Math.floor(hours / 24);
  
  if (days > 7) {
    return formatDate(dateString);
  } else if (days > 0) {
    return `${days} day${days > 1 ? 's' : ''} ago`;
  } else if (hours > 0) {
    return `${hours} hour${hours > 1 ? 's' : ''} ago`;
  } else if (minutes > 0) {
    return `${minutes} minute${minutes > 1 ? 's' : ''} ago`;
  } else {
    return 'Just now';
  }
}

/**
 * Show loading overlay
 * @param {string} message
 */
function showLoading(message = 'Loading...') {
  let overlay = document.querySelector('.pwa-loading-overlay');
  
  if (!overlay) {
    overlay = document.createElement('div');
    overlay.className = 'pwa-loading-overlay';
    overlay.innerHTML = `
      <div class="pwa-loading-spinner"></div>
      <p class="pwa-loading-message">${escapeHtml(message)}</p>
    `;
    document.body.appendChild(overlay);
  } else {
    overlay.querySelector('.pwa-loading-message').textContent = message;
  }
  
  overlay.classList.add('pwa-loading-show');
}

/**
 * Hide loading overlay
 */
function hideLoading() {
  const overlay = document.querySelector('.pwa-loading-overlay');
  if (overlay) {
    overlay.classList.remove('pwa-loading-show');
  }
}

/**
 * Show confirmation dialog
 * @param {string} title
 * @param {string} message
 * @param {Object} options
 * @returns {Promise<boolean>}
 */
function showConfirm(title, message, options = {}) {
  return new Promise((resolve) => {
    const dialog = document.createElement('div');
    dialog.className = 'pwa-dialog-overlay';
    dialog.innerHTML = `
      <div class="pwa-dialog">
        <h3 class="pwa-dialog-title">${escapeHtml(title)}</h3>
        <p class="pwa-dialog-message">${escapeHtml(message)}</p>
        <div class="pwa-dialog-buttons">
          <button class="pwa-btn pwa-btn-secondary" data-action="cancel">
            ${options.cancelText || 'Cancel'}
          </button>
          <button class="pwa-btn pwa-btn-primary" data-action="confirm">
            ${options.confirmText || 'Confirm'}
          </button>
        </div>
      </div>
    `;

    document.body.appendChild(dialog);

    requestAnimationFrame(() => {
      dialog.classList.add('pwa-dialog-show');
    });

    const close = (result) => {
      dialog.classList.remove('pwa-dialog-show');
      setTimeout(() => dialog.remove(), 300);
      resolve(result);
    };

    dialog.addEventListener('click', (e) => {
      const action = e.target.dataset.action;
      if (action === 'confirm') {
        close(true);
      } else if (action === 'cancel' || e.target === dialog) {
        close(false);
      }
    });
  });
}

/**
 * Show install prompt banner
 * @param {InstallTracker} installTracker
 */
function showInstallBanner(installTracker) {
  const banner = document.createElement('div');
  banner.className = 'pwa-install-banner';
  banner.innerHTML = `
    <div class="pwa-install-content">
      <span class="pwa-install-icon">📱</span>
      <div class="pwa-install-text">
        <strong>Install App</strong>
        <small>Add to home screen for the best experience</small>
      </div>
    </div>
    <div class="pwa-install-actions">
      <button class="pwa-btn pwa-btn-text" data-action="dismiss">Not now</button>
      <button class="pwa-btn pwa-btn-accent" data-action="install">Install</button>
    </div>
  `;

  document.body.appendChild(banner);

  requestAnimationFrame(() => {
    banner.classList.add('pwa-install-show');
  });

  banner.addEventListener('click', async (e) => {
    const action = e.target.dataset.action;
    if (action === 'install') {
      const installed = await installTracker.promptInstall();
      if (installed) {
        showToast('App installed successfully!', 'success');
      }
      banner.remove();
    } else if (action === 'dismiss') {
      banner.classList.remove('pwa-install-show');
      setTimeout(() => banner.remove(), 300);
      // Don't show again for 7 days
      localStorage.setItem('install_dismissed', Date.now().toString());
    }
  });
}

/**
 * Check if should show install banner
 * @returns {boolean}
 */
function shouldShowInstallBanner() {
  const dismissed = localStorage.getItem('install_dismissed');
  if (dismissed) {
    const dismissedTime = parseInt(dismissed, 10);
    const sevenDays = 7 * 24 * 60 * 60 * 1000;
    if (Date.now() - dismissedTime < sevenDays) {
      return false;
    }
  }
  return true;
}

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
  module.exports = {
    showToast,
    formatCurrency,
    formatDate,
    formatRelativeTime,
    showLoading,
    hideLoading,
    showConfirm,
    showInstallBanner,
    shouldShowInstallBanner,
    escapeHtml,
  };
}

