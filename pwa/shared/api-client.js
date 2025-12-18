/**
 * PWA API Client
 * 
 * Handles all API communication with token-based authentication.
 * Automatically refreshes tokens when they expire.
 */

const API_BASE = '/api/v1';

class ApiClient {
  constructor() {
    this.accessToken = localStorage.getItem('access_token');
    this.refreshToken = localStorage.getItem('refresh_token');
    this.tokenExpiry = localStorage.getItem('token_expiry');
    this.userType = localStorage.getItem('user_type');
    this.isRefreshing = false;
    this.refreshPromise = null;
  }

  /**
   * Store tokens after login
   * @param {Object} authData - Auth response from login endpoint
   */
  setTokens(authData) {
    this.accessToken = authData.access_token;
    this.refreshToken = authData.refresh_token;
    this.tokenExpiry = Date.now() + (authData.expires_in * 1000);
    this.userType = authData.user?.role || 'donor';

    localStorage.setItem('access_token', this.accessToken);
    localStorage.setItem('refresh_token', this.refreshToken);
    localStorage.setItem('token_expiry', this.tokenExpiry.toString());
    localStorage.setItem('user_type', this.userType);
    localStorage.setItem('user', JSON.stringify(authData.user));
  }

  /**
   * Clear tokens on logout
   */
  clearTokens() {
    this.accessToken = null;
    this.refreshToken = null;
    this.tokenExpiry = null;
    this.userType = null;

    localStorage.removeItem('access_token');
    localStorage.removeItem('refresh_token');
    localStorage.removeItem('token_expiry');
    localStorage.removeItem('user_type');
    localStorage.removeItem('user');
  }

  /**
   * Check if user is authenticated
   * @returns {boolean}
   */
  isAuthenticated() {
    return !!this.accessToken;
  }

  /**
   * Get current user data
   * @returns {Object|null}
   */
  getUser() {
    const user = localStorage.getItem('user');
    return user ? JSON.parse(user) : null;
  }

  /**
   * Check if token needs refresh (expires in less than 2 minutes)
   * @returns {boolean}
   */
  tokenNeedsRefresh() {
    if (!this.tokenExpiry) return true;
    return Date.now() > (this.tokenExpiry - 120000);
  }

  /**
   * Refresh the access token
   * @returns {Promise<boolean>}
   */
  async refreshAccessToken() {
    if (!this.refreshToken) {
      this.clearTokens();
      return false;
    }

    // Prevent multiple simultaneous refresh requests
    if (this.isRefreshing) {
      return this.refreshPromise;
    }

    this.isRefreshing = true;
    this.refreshPromise = (async () => {
      try {
        const response = await fetch(`${API_BASE}/auth/refresh.php`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({
            refresh_token: this.refreshToken,
          }),
        });

        if (!response.ok) {
          this.clearTokens();
          return false;
        }

        const data = await response.json();
        if (data.success && data.data) {
          this.setTokens(data.data);
          return true;
        }

        this.clearTokens();
        return false;
      } catch (error) {
        console.error('Token refresh failed:', error);
        this.clearTokens();
        return false;
      } finally {
        this.isRefreshing = false;
        this.refreshPromise = null;
      }
    })();

    return this.refreshPromise;
  }

  /**
   * Make an authenticated API request
   * @param {string} endpoint - API endpoint (without base)
   * @param {Object} options - Fetch options
   * @returns {Promise<Object>}
   */
  async request(endpoint, options = {}) {
    // Refresh token if needed
    if (this.isAuthenticated() && this.tokenNeedsRefresh()) {
      const refreshed = await this.refreshAccessToken();
      if (!refreshed && options.requireAuth !== false) {
        throw new Error('Session expired. Please log in again.');
      }
    }

    const url = endpoint.startsWith('http') ? endpoint : `${API_BASE}${endpoint}`;
    
    const headers = {
      'Content-Type': 'application/json',
      ...options.headers,
    };

    if (this.accessToken) {
      headers['Authorization'] = `Bearer ${this.accessToken}`;
    }

    try {
      const response = await fetch(url, {
        ...options,
        headers,
        body: options.body ? JSON.stringify(options.body) : undefined,
      });

      const data = await response.json();

      // Handle token expiry
      if (response.status === 401 && data.error?.code === 'INVALID_TOKEN') {
        if (await this.refreshAccessToken()) {
          // Retry request with new token
          headers['Authorization'] = `Bearer ${this.accessToken}`;
          const retryResponse = await fetch(url, {
            ...options,
            headers,
            body: options.body ? JSON.stringify(options.body) : undefined,
          });
          return retryResponse.json();
        }
        throw new Error('Session expired. Please log in again.');
      }

      return data;
    } catch (error) {
      if (error.name === 'TypeError' && error.message.includes('Failed to fetch')) {
        throw new Error('Network error. Please check your connection.');
      }
      throw error;
    }
  }

  /**
   * GET request
   * @param {string} endpoint
   * @param {Object} params - Query parameters
   * @returns {Promise<Object>}
   */
  async get(endpoint, params = {}) {
    const queryString = new URLSearchParams(params).toString();
    const url = queryString ? `${endpoint}?${queryString}` : endpoint;
    return this.request(url, { method: 'GET' });
  }

  /**
   * POST request
   * @param {string} endpoint
   * @param {Object} body
   * @returns {Promise<Object>}
   */
  async post(endpoint, body = {}) {
    return this.request(endpoint, { method: 'POST', body });
  }

  /**
   * PUT request
   * @param {string} endpoint
   * @param {Object} body
   * @returns {Promise<Object>}
   */
  async put(endpoint, body = {}) {
    return this.request(endpoint, { method: 'PUT', body });
  }

  /**
   * DELETE request
   * @param {string} endpoint
   * @returns {Promise<Object>}
   */
  async delete(endpoint) {
    return this.request(endpoint, { method: 'DELETE' });
  }
}

// Create singleton instance
const api = new ApiClient();

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
  module.exports = { ApiClient, api };
}

