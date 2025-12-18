/**
 * PWA Auth Module
 * 
 * Handles login, logout, and session management.
 */

class AuthManager {
  constructor(apiClient, userType = 'donor') {
    this.api = apiClient;
    this.userType = userType;
    this.onAuthChange = null;
  }

  /**
   * Set callback for auth state changes
   * @param {Function} callback
   */
  setOnAuthChange(callback) {
    this.onAuthChange = callback;
  }

  /**
   * Notify auth state change
   * @param {boolean} isAuthenticated
   */
  notifyAuthChange(isAuthenticated) {
    if (this.onAuthChange) {
      this.onAuthChange(isAuthenticated, this.api.getUser());
    }
  }

  /**
   * Check if user is logged in
   * @returns {boolean}
   */
  isLoggedIn() {
    return this.api.isAuthenticated();
  }

  /**
   * Get current user
   * @returns {Object|null}
   */
  getUser() {
    return this.api.getUser();
  }

  /**
   * Request OTP for donor login
   * @param {string} phone
   * @returns {Promise<Object>}
   */
  async requestOtp(phone) {
    const response = await this.api.post('/auth/otp-send.php', { phone });
    
    if (!response.success) {
      throw new Error(response.error?.message || 'Failed to send OTP');
    }
    
    return response.data;
  }

  /**
   * Login as donor with OTP
   * @param {string} phone
   * @param {string} otpCode
   * @returns {Promise<Object>}
   */
  async loginDonor(phone, otpCode) {
    const response = await this.api.post('/auth/login.php', {
      user_type: 'donor',
      phone,
      otp_code: otpCode,
    });

    if (!response.success) {
      throw new Error(response.error?.message || 'Login failed');
    }

    this.api.setTokens(response.data);
    this.notifyAuthChange(true);
    
    return response.data.user;
  }

  /**
   * Login as admin/registrar with password
   * @param {string} phone
   * @param {string} password
   * @returns {Promise<Object>}
   */
  async loginUser(phone, password) {
    const response = await this.api.post('/auth/login.php', {
      user_type: 'user',
      phone,
      password,
    });

    if (!response.success) {
      throw new Error(response.error?.message || 'Login failed');
    }

    this.api.setTokens(response.data);
    this.notifyAuthChange(true);
    
    return response.data.user;
  }

  /**
   * Logout
   * @returns {Promise<void>}
   */
  async logout() {
    try {
      await this.api.post('/auth/logout.php');
    } catch (error) {
      console.error('Logout error:', error);
    } finally {
      this.api.clearTokens();
      this.notifyAuthChange(false);
    }
  }

  /**
   * Get current user from API
   * @returns {Promise<Object>}
   */
  async getCurrentUser() {
    const response = await this.api.get('/auth/me.php');
    
    if (!response.success) {
      throw new Error(response.error?.message || 'Failed to get user');
    }
    
    return response.data;
  }

  /**
   * Require authentication - redirect to login if not authenticated
   * @param {string} loginUrl
   */
  requireAuth(loginUrl = 'login.html') {
    if (!this.isLoggedIn()) {
      window.location.href = loginUrl;
      return false;
    }
    return true;
  }
}

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
  module.exports = { AuthManager };
}

