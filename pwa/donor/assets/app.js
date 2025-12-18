/**
 * Donor PWA Application
 */

// Initialize modules
const auth = new AuthManager(api, 'donor');
const installTracker = new InstallTracker(api);

// State
let currentView = 'dashboard';
let historyPage = 1;

/**
 * Initialize the app
 */
async function init() {
  // Register service worker
  registerServiceWorker();
  
  // Check authentication
  if (!auth.requireAuth('login.html')) {
    return;
  }
  
  // Check for pending install log
  await installTracker.checkPendingInstallLog();
  
  // Set up UI
  setupNavigation();
  setupForms();
  setupLogout();
  
  // Load initial data
  await loadDashboard();
  
  // Update welcome text
  const user = auth.getUser();
  if (user && user.name) {
    document.getElementById('welcomeText').textContent = `Welcome, ${user.name}`;
  }
  
  // Check if just logged in - show install prompt
  if (sessionStorage.getItem('just_logged_in') === 'true') {
    sessionStorage.removeItem('just_logged_in');
    
    // Show install prompt modal if not already installed
    if (typeof installPrompt !== 'undefined' && !installPrompt.isInstalled) {
      installPrompt.showAfterLogin('Donor Portal');
    }
  }
  
  // Handle hash navigation
  handleHashChange();
  window.addEventListener('hashchange', handleHashChange);
}

/**
 * Register service worker
 */
async function registerServiceWorker() {
  if ('serviceWorker' in navigator) {
    try {
      const registration = await navigator.serviceWorker.register('sw.js');
      console.log('SW registered:', registration.scope);
    } catch (error) {
      console.error('SW registration failed:', error);
    }
  }
}

/**
 * Set up navigation
 */
function setupNavigation() {
  const navItems = document.querySelectorAll('.pwa-nav-item');
  
  navItems.forEach(item => {
    item.addEventListener('click', (e) => {
      const view = item.dataset.view;
      if (view) {
        e.preventDefault();
        navigateTo(view);
      }
    });
  });
}

/**
 * Navigate to a view
 */
function navigateTo(view) {
  // Update nav
  document.querySelectorAll('.pwa-nav-item').forEach(item => {
    item.classList.toggle('active', item.dataset.view === view);
  });
  
  // Update views
  document.querySelectorAll('.view').forEach(v => {
    v.classList.remove('active');
  });
  
  const viewEl = document.getElementById(`${view}View`);
  if (viewEl) {
    viewEl.classList.add('active');
  }
  
  currentView = view;
  
  // Load view data
  if (view === 'history' && historyPage === 1) {
    loadHistory();
  } else if (view === 'profile') {
    loadProfile();
  }
  
  // Update hash without triggering hashchange
  history.replaceState(null, '', `#${view}`);
}

/**
 * Handle hash changes
 */
function handleHashChange() {
  const hash = window.location.hash.slice(1) || 'dashboard';
  const validViews = ['dashboard', 'payments', 'history', 'profile'];
  
  if (validViews.includes(hash)) {
    navigateTo(hash);
  }
}

/**
 * Set up forms
 */
function setupForms() {
  // Payment form
  const paymentForm = document.getElementById('paymentForm');
  if (paymentForm) {
    // Set default date to today
    document.getElementById('paymentDate').value = new Date().toISOString().split('T')[0];
    
    paymentForm.addEventListener('submit', handlePaymentSubmit);
  }
  
  // Preferences form
  const preferencesForm = document.getElementById('preferencesForm');
  if (preferencesForm) {
    preferencesForm.addEventListener('submit', handlePreferencesSubmit);
  }
}

/**
 * Set up logout
 */
function setupLogout() {
  const logoutBtn = document.getElementById('logoutBtn');
  if (logoutBtn) {
    logoutBtn.addEventListener('click', async () => {
      const confirmed = await showConfirm('Logout', 'Are you sure you want to logout?');
      if (confirmed) {
        showLoading('Logging out...');
        await auth.logout();
        window.location.href = 'login.html';
      }
    });
  }
}

/**
 * Load dashboard data
 */
async function loadDashboard() {
  try {
    const response = await api.get('/donor/summary.php');
    
    if (!response.success) {
      throw new Error(response.error?.message || 'Failed to load dashboard');
    }
    
    const data = response.data;
    
    // Update summary cards
    document.getElementById('totalPledged').textContent = formatCurrency(data.financials.total_pledged);
    document.getElementById('totalPaid').textContent = formatCurrency(data.financials.total_paid);
    document.getElementById('balance').textContent = formatCurrency(data.financials.balance);
    document.getElementById('progress').textContent = `${data.financials.progress_percentage}%`;
    
    // Update progress bar
    document.getElementById('progressBar').style.width = `${data.financials.progress_percentage}%`;
    
    // Update payment plan
    if (data.payment_plan) {
      const planCard = document.getElementById('paymentPlanCard');
      planCard.style.display = 'block';
      
      document.getElementById('nextPaymentDate').textContent = formatDate(data.payment_plan.next_payment_date);
      document.getElementById('installmentAmount').textContent = formatCurrency(data.payment_plan.installment_amount);
      document.getElementById('planProgress').textContent = 
        `${data.payment_plan.progress.completed} of ${data.payment_plan.progress.total}`;
    }
    
    // Update recent payments
    const list = document.getElementById('recentPaymentsList');
    if (data.recent_payments && data.recent_payments.length > 0) {
      list.innerHTML = data.recent_payments.map(payment => `
        <li class="pwa-list-item">
          <div class="pwa-list-item-content">
            <div class="pwa-list-item-title">${formatCurrency(payment.amount)}</div>
            <div class="pwa-list-item-subtitle">${formatDate(payment.payment_date)}</div>
          </div>
          <span class="pwa-badge pwa-badge-${getStatusBadge(payment.status)}">${payment.status}</span>
        </li>
      `).join('');
    } else {
      list.innerHTML = '<li class="pwa-list-item empty-state"><span class="pwa-text-muted">No recent payments</span></li>';
    }
    
    // Update welcome text
    if (data.donor && data.donor.name) {
      document.getElementById('welcomeText').textContent = `Welcome, ${data.donor.name}`;
    }
  } catch (error) {
    console.error('Dashboard load error:', error);
    showToast(error.message, 'error');
  }
}

/**
 * Load payment history
 */
async function loadHistory(page = 1) {
  const list = document.getElementById('historyList');
  list.innerHTML = '<li class="pwa-list-item empty-state"><span class="pwa-text-muted">Loading...</span></li>';
  
  try {
    const response = await api.get('/donor/payments.php', { page, per_page: 20 });
    
    if (!response.success) {
      throw new Error(response.error?.message || 'Failed to load history');
    }
    
    const payments = response.data;
    const pagination = response.pagination;
    
    if (payments.length > 0) {
      list.innerHTML = payments.map(payment => `
        <li class="pwa-list-item">
          <div class="pwa-list-item-content">
            <div class="pwa-list-item-title">${formatCurrency(payment.amount)}</div>
            <div class="pwa-list-item-subtitle">
              ${formatDate(payment.payment_date)} · ${payment.payment_method || 'N/A'}
            </div>
          </div>
          <span class="pwa-badge pwa-badge-${getStatusBadge(payment.status)}">${payment.status}</span>
        </li>
      `).join('');
      
      // Update pagination
      updatePagination('historyPagination', pagination, loadHistory);
    } else {
      list.innerHTML = '<li class="pwa-list-item empty-state"><span class="pwa-text-muted">No payment history</span></li>';
    }
    
    historyPage = page;
  } catch (error) {
    console.error('History load error:', error);
    list.innerHTML = '<li class="pwa-list-item empty-state"><span class="pwa-text-muted">Failed to load history</span></li>';
    showToast(error.message, 'error');
  }
}

/**
 * Load profile
 */
async function loadProfile() {
  try {
    const response = await api.get('/donor/profile.php');
    
    if (!response.success) {
      throw new Error(response.error?.message || 'Failed to load profile');
    }
    
    const data = response.data;
    
    document.getElementById('profileName').textContent = data.name || '-';
    document.getElementById('profilePhone').textContent = data.phone || '-';
    document.getElementById('profileMemberSince').textContent = data.member_since ? formatDate(data.member_since) : '-';
    
    // Set preferences
    document.getElementById('prefPaymentMethod').value = data.preferred_payment_method || '';
    document.getElementById('prefLanguage').value = data.preferred_language || 'en';
  } catch (error) {
    console.error('Profile load error:', error);
    showToast(error.message, 'error');
  }
}

/**
 * Handle payment form submission
 */
async function handlePaymentSubmit(e) {
  e.preventDefault();
  
  const amount = parseFloat(document.getElementById('paymentAmount').value);
  const method = document.getElementById('paymentMethod').value;
  const date = document.getElementById('paymentDate').value;
  const reference = document.getElementById('referenceNumber').value;
  const notes = document.getElementById('paymentNotes').value;
  
  if (!amount || amount <= 0) {
    showToast('Please enter a valid amount', 'error');
    return;
  }
  
  showLoading('Submitting payment...');
  
  try {
    const response = await api.post('/donor/payments.php', {
      amount,
      payment_method: method,
      payment_date: date,
      reference_number: reference || null,
      notes: notes || null,
    });
    
    if (!response.success) {
      throw new Error(response.error?.message || 'Failed to submit payment');
    }
    
    showToast('Payment submitted for approval!', 'success');
    
    // Reset form
    e.target.reset();
    document.getElementById('paymentDate').value = new Date().toISOString().split('T')[0];
    
    // Refresh dashboard
    await loadDashboard();
    
    // Navigate to dashboard
    navigateTo('dashboard');
  } catch (error) {
    console.error('Payment submit error:', error);
    showToast(error.message, 'error');
  } finally {
    hideLoading();
  }
}

/**
 * Handle preferences form submission
 */
async function handlePreferencesSubmit(e) {
  e.preventDefault();
  
  const method = document.getElementById('prefPaymentMethod').value;
  const language = document.getElementById('prefLanguage').value;
  
  showLoading('Saving preferences...');
  
  try {
    const response = await api.put('/donor/profile.php', {
      preferred_payment_method: method || null,
      preferred_language: language,
    });
    
    if (!response.success) {
      throw new Error(response.error?.message || 'Failed to save preferences');
    }
    
    showToast('Preferences saved!', 'success');
  } catch (error) {
    console.error('Preferences save error:', error);
    showToast(error.message, 'error');
  } finally {
    hideLoading();
  }
}

/**
 * Get status badge class
 */
function getStatusBadge(status) {
  const badges = {
    approved: 'success',
    pending: 'pending',
    rejected: 'danger',
    paid: 'success',
    partial: 'warning',
    unpaid: 'danger',
  };
  return badges[status] || 'info';
}

/**
 * Update pagination controls
 */
function updatePagination(containerId, pagination, loadFn) {
  const container = document.getElementById(containerId);
  if (!container || !pagination) return;
  
  const { page, total_pages, has_more } = pagination;
  
  if (total_pages <= 1) {
    container.innerHTML = '';
    return;
  }
  
  container.innerHTML = `
    <div class="pagination-controls">
      <button class="pwa-btn pwa-btn-text" ${page <= 1 ? 'disabled' : ''} data-page="${page - 1}">
        ← Previous
      </button>
      <span class="pagination-info">Page ${page} of ${total_pages}</span>
      <button class="pwa-btn pwa-btn-text" ${!has_more ? 'disabled' : ''} data-page="${page + 1}">
        Next →
      </button>
    </div>
  `;
  
  container.querySelectorAll('button[data-page]').forEach(btn => {
    btn.addEventListener('click', () => {
      const newPage = parseInt(btn.dataset.page, 10);
      loadFn(newPage);
    });
  });
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', init);

