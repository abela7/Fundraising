/**
 * PWA Install Tracker
 * 
 * Handles PWA installation detection and logging.
 */

class InstallTracker {
  constructor(apiClient) {
    this.api = apiClient;
    this.deferredPrompt = null;
    this.isInstalled = false;
    this.onInstallAvailable = null;
    this.onInstallComplete = null;
    
    this.init();
  }

  /**
   * Initialize install tracking
   */
  init() {
    // Check if already installed
    this.isInstalled = this.checkIfInstalled();
    
    // Listen for beforeinstallprompt event
    window.addEventListener('beforeinstallprompt', (e) => {
      e.preventDefault();
      this.deferredPrompt = e;
      
      if (this.onInstallAvailable) {
        this.onInstallAvailable();
      }
    });

    // Listen for appinstalled event
    window.addEventListener('appinstalled', () => {
      this.isInstalled = true;
      this.deferredPrompt = null;
      this.logInstallation();
      
      if (this.onInstallComplete) {
        this.onInstallComplete();
      }
    });

    // Send heartbeat if running as installed app
    if (this.isInstalled) {
      this.sendHeartbeat();
      
      // Send heartbeat every 5 minutes while app is open
      setInterval(() => this.sendHeartbeat(), 5 * 60 * 1000);
    }
  }

  /**
   * Check if app is installed (running in standalone mode)
   * @returns {boolean}
   */
  checkIfInstalled() {
    // Check display-mode
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
   * Check if install prompt is available
   * @returns {boolean}
   */
  canInstall() {
    return !!this.deferredPrompt;
  }

  /**
   * Show install prompt
   * @returns {Promise<boolean>}
   */
  async promptInstall() {
    if (!this.deferredPrompt) {
      return false;
    }

    try {
      this.deferredPrompt.prompt();
      const { outcome } = await this.deferredPrompt.userChoice;
      
      if (outcome === 'accepted') {
        this.isInstalled = true;
        this.logInstallation();
        return true;
      }
      
      return false;
    } catch (error) {
      console.error('Install prompt error:', error);
      return false;
    } finally {
      this.deferredPrompt = null;
    }
  }

  /**
   * Get device info for logging
   * @returns {Object}
   */
  getDeviceInfo() {
    const ua = navigator.userAgent;
    let deviceType = 'desktop';
    let devicePlatform = 'Unknown';
    let browser = 'Unknown';

    // Detect device type
    if (/Android/i.test(ua)) {
      deviceType = 'android';
      const match = ua.match(/Android\s([0-9.]+)/);
      devicePlatform = match ? `Android ${match[1]}` : 'Android';
    } else if (/iPhone|iPad|iPod/i.test(ua)) {
      deviceType = 'ios';
      const match = ua.match(/OS\s([0-9_]+)/);
      devicePlatform = match ? `iOS ${match[1].replace(/_/g, '.')}` : 'iOS';
    } else if (/Windows/i.test(ua)) {
      devicePlatform = 'Windows';
    } else if (/Mac/i.test(ua)) {
      devicePlatform = 'macOS';
    } else if (/Linux/i.test(ua)) {
      devicePlatform = 'Linux';
    }

    // Detect browser
    if (/Chrome/i.test(ua) && !/Edg/i.test(ua)) {
      const match = ua.match(/Chrome\/([0-9.]+)/);
      browser = match ? `Chrome ${match[1].split('.')[0]}` : 'Chrome';
    } else if (/Safari/i.test(ua) && !/Chrome/i.test(ua)) {
      const match = ua.match(/Version\/([0-9.]+)/);
      browser = match ? `Safari ${match[1].split('.')[0]}` : 'Safari';
    } else if (/Firefox/i.test(ua)) {
      const match = ua.match(/Firefox\/([0-9.]+)/);
      browser = match ? `Firefox ${match[1].split('.')[0]}` : 'Firefox';
    } else if (/Edg/i.test(ua)) {
      const match = ua.match(/Edg\/([0-9.]+)/);
      browser = match ? `Edge ${match[1].split('.')[0]}` : 'Edge';
    } else if (/SamsungBrowser/i.test(ua)) {
      browser = 'Samsung Internet';
    }

    return {
      device_type: deviceType,
      device_platform: devicePlatform,
      browser: browser,
      screen_width: window.screen.width,
      screen_height: window.screen.height,
      app_version: '1.0.0',
    };
  }

  /**
   * Log installation to server
   */
  async logInstallation() {
    if (!this.api.isAuthenticated()) {
      // Store for later when user logs in
      localStorage.setItem('pending_install_log', JSON.stringify({
        ...this.getDeviceInfo(),
        timestamp: Date.now(),
      }));
      return;
    }

    try {
      await this.api.post('/pwa/install-log.php', this.getDeviceInfo());
    } catch (error) {
      console.error('Failed to log installation:', error);
    }
  }

  /**
   * Send heartbeat to update last_opened_at
   */
  async sendHeartbeat() {
    if (!this.api.isAuthenticated()) {
      return;
    }

    try {
      await this.api.post('/pwa/heartbeat.php', {
        device_type: this.getDeviceInfo().device_type,
        is_standalone: this.isInstalled,
      });
    } catch (error) {
      console.error('Heartbeat failed:', error);
    }
  }

  /**
   * Check and log any pending installation
   */
  async checkPendingInstallLog() {
    const pending = localStorage.getItem('pending_install_log');
    if (pending && this.api.isAuthenticated()) {
      try {
        const data = JSON.parse(pending);
        await this.api.post('/pwa/install-log.php', data);
        localStorage.removeItem('pending_install_log');
      } catch (error) {
        console.error('Failed to log pending installation:', error);
      }
    }
  }

  /**
   * Set callback for install prompt available
   * @param {Function} callback
   */
  setOnInstallAvailable(callback) {
    this.onInstallAvailable = callback;
    
    // Call immediately if already available
    if (this.deferredPrompt) {
      callback();
    }
  }

  /**
   * Set callback for install complete
   * @param {Function} callback
   */
  setOnInstallComplete(callback) {
    this.onInstallComplete = callback;
  }
}

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
  module.exports = { InstallTracker };
}

