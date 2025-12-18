/**
 * PWA Install Prompt Module
 * 
 * Shows a prominent install modal after login.
 * Handles install detection and app-open redirection.
 */

class InstallPrompt {
  constructor() {
    this.deferredPrompt = null;
    this.isInstalled = false;
    this.hasShownPrompt = false;
    
    this.init();
  }

  /**
   * Initialize install prompt handling
   */
  init() {
    // Check if already installed
    this.isInstalled = this.checkIfInstalled();
    
    // Listen for beforeinstallprompt
    window.addEventListener('beforeinstallprompt', (e) => {
      e.preventDefault();
      this.deferredPrompt = e;
      console.log('[InstallPrompt] Install prompt available');
    });

    // Listen for app installed
    window.addEventListener('appinstalled', () => {
      console.log('[InstallPrompt] App installed!');
      this.isInstalled = true;
      this.deferredPrompt = null;
      this.hideModal();
      
      // Show success message
      if (typeof showToast === 'function') {
        showToast('App installed successfully! You can now use it from your home screen.', 'success', 5000);
      }
    });

    // Check on visibility change (for when user returns to tab)
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'visible') {
        this.isInstalled = this.checkIfInstalled();
      }
    });
  }

  /**
   * Check if app is running in standalone mode (installed)
   */
  checkIfInstalled() {
    // Check display-mode media query
    if (window.matchMedia('(display-mode: standalone)').matches) {
      return true;
    }
    
    // Check iOS standalone
    if (window.navigator.standalone === true) {
      return true;
    }
    
    // Check Android TWA
    if (document.referrer.includes('android-app://')) {
      return true;
    }
    
    return false;
  }

  /**
   * Check if install is available
   */
  canInstall() {
    return this.deferredPrompt !== null && !this.isInstalled;
  }

  /**
   * Check if should show install prompt (hasn't been dismissed recently)
   */
  shouldShowPrompt() {
    if (this.isInstalled) return false;
    if (this.hasShownPrompt) return false;
    
    const dismissed = localStorage.getItem('install_modal_dismissed');
    if (dismissed) {
      const dismissedTime = parseInt(dismissed, 10);
      const oneDay = 24 * 60 * 60 * 1000;
      if (Date.now() - dismissedTime < oneDay) {
        return false;
      }
    }
    return true;
  }

  /**
   * Show install modal after login
   * @param {string} appName - Name of the app (Donor, Registrar, Admin)
   */
  showAfterLogin(appName = 'App') {
    if (!this.shouldShowPrompt()) {
      console.log('[InstallPrompt] Not showing - already installed or dismissed');
      return;
    }
    
    this.hasShownPrompt = true;
    
    // Small delay to let the page render first
    setTimeout(() => {
      this.showModal(appName);
    }, 1000);
  }

  /**
   * Show the install modal
   */
  showModal(appName) {
    // Remove existing modal if any
    const existing = document.querySelector('.install-modal-overlay');
    if (existing) existing.remove();

    const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent);
    const canAutoInstall = this.canInstall();

    const modal = document.createElement('div');
    modal.className = 'install-modal-overlay';
    modal.innerHTML = `
      <div class="install-modal">
        <div class="install-modal-header">
          <div class="install-modal-icon">📱</div>
          <h2 class="install-modal-title">Install ${appName}</h2>
        </div>
        
        <p class="install-modal-description">
          Install this app on your device for a better experience:
        </p>
        
        <ul class="install-benefits">
          <li>✓ Quick access from your home screen</li>
          <li>✓ Works offline</li>
          <li>✓ Faster loading times</li>
          <li>✓ Full screen experience</li>
        </ul>
        
        ${isIOS && !canAutoInstall ? `
          <div class="install-ios-instructions">
            <p><strong>To install on iOS:</strong></p>
            <ol>
              <li>Tap the <span class="ios-share-icon">⬆️</span> Share button</li>
              <li>Scroll down and tap <strong>"Add to Home Screen"</strong></li>
              <li>Tap <strong>"Add"</strong> in the top right</li>
            </ol>
          </div>
        ` : ''}
        
        <div class="install-modal-actions">
          <button class="install-btn-later" data-action="later">Maybe later</button>
          ${canAutoInstall ? `
            <button class="install-btn-install" data-action="install">Install Now</button>
          ` : `
            <button class="install-btn-ok" data-action="ok">Got it</button>
          `}
        </div>
      </div>
    `;

    // Add styles
    if (!document.querySelector('#install-modal-styles')) {
      const styles = document.createElement('style');
      styles.id = 'install-modal-styles';
      styles.textContent = `
        .install-modal-overlay {
          position: fixed;
          inset: 0;
          background: rgba(0, 0, 0, 0.6);
          display: flex;
          align-items: center;
          justify-content: center;
          z-index: 10001;
          padding: 1rem;
          animation: fadeIn 0.3s ease;
          backdrop-filter: blur(4px);
        }
        
        @keyframes fadeIn {
          from { opacity: 0; }
          to { opacity: 1; }
        }
        
        .install-modal {
          background: #fff;
          border-radius: 16px;
          padding: 1.5rem;
          max-width: 360px;
          width: 100%;
          box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
          animation: slideUp 0.3s ease;
        }
        
        @keyframes slideUp {
          from { opacity: 0; transform: translateY(30px); }
          to { opacity: 1; transform: translateY(0); }
        }
        
        .install-modal-header {
          text-align: center;
          margin-bottom: 1rem;
        }
        
        .install-modal-icon {
          font-size: 3rem;
          margin-bottom: 0.5rem;
        }
        
        .install-modal-title {
          margin: 0;
          font-size: 1.5rem;
          color: #0a6286;
        }
        
        .install-modal-description {
          color: #666;
          text-align: center;
          margin-bottom: 1rem;
        }
        
        .install-benefits {
          list-style: none;
          padding: 0;
          margin: 0 0 1.5rem;
          background: #f8f9fa;
          border-radius: 12px;
          padding: 1rem;
        }
        
        .install-benefits li {
          padding: 0.5rem 0;
          color: #333;
          display: flex;
          align-items: center;
          gap: 0.5rem;
        }
        
        .install-benefits li:not(:last-child) {
          border-bottom: 1px solid #e9ecef;
        }
        
        .install-ios-instructions {
          background: #e6f3f8;
          border-radius: 12px;
          padding: 1rem;
          margin-bottom: 1.5rem;
        }
        
        .install-ios-instructions p {
          margin: 0 0 0.5rem;
          color: #0a6286;
        }
        
        .install-ios-instructions ol {
          margin: 0;
          padding-left: 1.25rem;
        }
        
        .install-ios-instructions li {
          padding: 0.25rem 0;
          color: #333;
        }
        
        .ios-share-icon {
          display: inline-block;
          background: #007aff;
          color: white;
          width: 24px;
          height: 24px;
          border-radius: 4px;
          text-align: center;
          line-height: 24px;
          font-size: 14px;
        }
        
        .install-modal-actions {
          display: flex;
          gap: 0.75rem;
        }
        
        .install-btn-later, .install-btn-ok {
          flex: 1;
          padding: 0.875rem 1rem;
          border: 2px solid #dee2e6;
          background: transparent;
          border-radius: 10px;
          font-size: 1rem;
          font-weight: 500;
          color: #666;
          cursor: pointer;
          transition: all 0.2s;
        }
        
        .install-btn-later:hover, .install-btn-ok:hover {
          background: #f8f9fa;
          border-color: #0a6286;
          color: #0a6286;
        }
        
        .install-btn-install {
          flex: 1;
          padding: 0.875rem 1rem;
          border: none;
          background: linear-gradient(135deg, #0a6286 0%, #084a66 100%);
          border-radius: 10px;
          font-size: 1rem;
          font-weight: 600;
          color: white;
          cursor: pointer;
          transition: all 0.2s;
          box-shadow: 0 4px 12px rgba(10, 98, 134, 0.3);
        }
        
        .install-btn-install:hover {
          transform: translateY(-2px);
          box-shadow: 0 6px 16px rgba(10, 98, 134, 0.4);
        }
      `;
      document.head.appendChild(styles);
    }

    document.body.appendChild(modal);

    // Handle button clicks
    modal.addEventListener('click', async (e) => {
      const action = e.target.dataset.action;
      
      if (action === 'install') {
        await this.triggerInstall();
        this.hideModal();
      } else if (action === 'later') {
        localStorage.setItem('install_modal_dismissed', Date.now().toString());
        this.hideModal();
      } else if (action === 'ok') {
        this.hideModal();
      } else if (e.target === modal) {
        // Click on backdrop
        this.hideModal();
      }
    });
  }

  /**
   * Trigger the native install prompt
   */
  async triggerInstall() {
    if (!this.deferredPrompt) {
      console.log('[InstallPrompt] No deferred prompt available');
      return false;
    }

    try {
      this.deferredPrompt.prompt();
      const { outcome } = await this.deferredPrompt.userChoice;
      console.log('[InstallPrompt] User choice:', outcome);
      
      this.deferredPrompt = null;
      return outcome === 'accepted';
    } catch (error) {
      console.error('[InstallPrompt] Install error:', error);
      return false;
    }
  }

  /**
   * Hide the install modal
   */
  hideModal() {
    const modal = document.querySelector('.install-modal-overlay');
    if (modal) {
      modal.style.animation = 'fadeIn 0.2s ease reverse';
      setTimeout(() => modal.remove(), 200);
    }
  }
}

// Create global instance
const installPrompt = new InstallPrompt();

// Export for module systems
if (typeof module !== 'undefined' && module.exports) {
  module.exports = { InstallPrompt, installPrompt };
}

