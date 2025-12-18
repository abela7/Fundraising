/**
 * Registrar PWA Application
 */

const auth = new AuthManager(api, 'registrar');
const installTracker = new InstallTracker(api);

let currentView = 'dashboard';
let registrationsPage = 1;
let statusFilter = '';

async function init() {
  registerServiceWorker();
  
  if (!auth.requireAuth('login.html')) return;
  
  // Check role
  const user = auth.getUser();
  if (!user || !['registrar', 'admin'].includes(user.role)) {
    auth.logout();
    window.location.href = 'login.html';
    return;
  }
  
  await installTracker.checkPendingInstallLog();
  
  setupNavigation();
  setupFilters();
  setupLogout();
  
  await loadDashboard();
  
  document.getElementById('welcomeText').textContent = `Welcome, ${user.name || 'Registrar'}`;
  
  // Hide install FAB if already installed
  hideInstallFabIfInstalled();
  
  handleHashChange();
  window.addEventListener('hashchange', handleHashChange);
}

function hideInstallFabIfInstalled() {
  const isStandalone = window.matchMedia('(display-mode: standalone)').matches || 
                       window.navigator.standalone === true;
  
  if (isStandalone) {
    const fab = document.getElementById('installFab');
    if (fab) fab.style.display = 'none';
  }
}

async function registerServiceWorker() {
  if ('serviceWorker' in navigator) {
    try {
      await navigator.serviceWorker.register('sw.js');
    } catch (error) {
      console.error('SW registration failed:', error);
    }
  }
}

function setupNavigation() {
  document.querySelectorAll('.pwa-nav-item').forEach(item => {
    item.addEventListener('click', (e) => {
      const view = item.dataset.view;
      if (view) {
        e.preventDefault();
        navigateTo(view);
      }
    });
  });
}

function navigateTo(view) {
  document.querySelectorAll('.pwa-nav-item').forEach(item => {
    item.classList.toggle('active', item.dataset.view === view);
  });
  
  document.querySelectorAll('.view').forEach(v => v.classList.remove('active'));
  
  const viewEl = document.getElementById(`${view}View`);
  if (viewEl) viewEl.classList.add('active');
  
  currentView = view;
  
  if (view === 'registrations') loadRegistrations();
  else if (view === 'stats') loadStatistics();
  else if (view === 'profile') loadProfile();
  
  history.replaceState(null, '', `#${view}`);
}

function handleHashChange() {
  const hash = window.location.hash.slice(1) || 'dashboard';
  const validViews = ['dashboard', 'registrations', 'stats', 'profile'];
  if (validViews.includes(hash)) navigateTo(hash);
}

function setupFilters() {
  const filter = document.getElementById('statusFilter');
  if (filter) {
    filter.addEventListener('change', () => {
      statusFilter = filter.value;
      registrationsPage = 1;
      loadRegistrations();
    });
  }
}

function setupLogout() {
  document.getElementById('logoutBtn')?.addEventListener('click', async () => {
    const confirmed = await showConfirm('Logout', 'Are you sure?');
    if (confirmed) {
      showLoading('Logging out...');
      await auth.logout();
      window.location.href = 'login.html';
    }
  });
}

async function loadDashboard() {
  try {
    const response = await api.get('/registrar/statistics.php');
    if (!response.success) throw new Error(response.error?.message);
    
    const data = response.data;
    
    document.getElementById('totalRegistrations').textContent = data.pledges.total;
    document.getElementById('totalAmount').textContent = formatCurrency(data.pledges.total_amount);
    document.getElementById('pendingCount').textContent = data.pledges.pending;
    document.getElementById('thisMonth').textContent = data.this_month.pledges;
    
    // Load recent
    const regResponse = await api.get('/registrar/registrations.php', { per_page: 5 });
    if (regResponse.success && regResponse.data.length > 0) {
      document.getElementById('recentList').innerHTML = regResponse.data.map(r => `
        <li class="pwa-list-item">
          <div class="pwa-list-item-content">
            <div class="pwa-list-item-title">${escapeHtml(r.donor_name)}</div>
            <div class="pwa-list-item-subtitle">${formatCurrency(r.amount)} · ${formatDate(r.created_at)}</div>
          </div>
          <span class="pwa-badge pwa-badge-${getStatusBadge(r.status)}">${r.status}</span>
        </li>
      `).join('');
    }
  } catch (error) {
    showToast(error.message, 'error');
  }
}

async function loadRegistrations(page = 1) {
  const list = document.getElementById('registrationsList');
  list.innerHTML = '<li class="pwa-list-item empty-state">Loading...</li>';
  
  try {
    const params = { page, per_page: 20 };
    if (statusFilter) params.status = statusFilter;
    
    const response = await api.get('/registrar/registrations.php', params);
    if (!response.success) throw new Error(response.error?.message);
    
    const items = response.data;
    
    if (items.length > 0) {
      list.innerHTML = items.map(r => `
        <li class="pwa-list-item">
          <div class="pwa-list-item-content">
            <div class="pwa-list-item-title">${escapeHtml(r.donor_name)}</div>
            <div class="pwa-list-item-subtitle">${formatCurrency(r.amount)} · ${formatDate(r.pledge_date)}</div>
          </div>
          <span class="pwa-badge pwa-badge-${getStatusBadge(r.status)}">${r.status}</span>
        </li>
      `).join('');
      
      updatePagination('registrationsPagination', response.pagination, loadRegistrations);
    } else {
      list.innerHTML = '<li class="pwa-list-item empty-state">No registrations found</li>';
    }
    
    registrationsPage = page;
  } catch (error) {
    list.innerHTML = '<li class="pwa-list-item empty-state">Failed to load</li>';
    showToast(error.message, 'error');
  }
}

async function loadStatistics() {
  try {
    const response = await api.get('/registrar/statistics.php');
    if (!response.success) throw new Error(response.error?.message);
    
    const data = response.data;
    
    document.getElementById('statPledgesTotal').textContent = data.pledges.total;
    document.getElementById('statPledgesApproved').textContent = data.pledges.approved;
    document.getElementById('statPledgesPending').textContent = data.pledges.pending;
    document.getElementById('statPledgesAmount').textContent = formatCurrency(data.pledges.total_amount);
    
    document.getElementById('statPaymentsTotal').textContent = data.payments.total;
    document.getElementById('statPaymentsApproved').textContent = data.payments.approved;
    document.getElementById('statPaymentsCollected').textContent = formatCurrency(data.payments.total_collected);
    
    document.getElementById('statMonthPledges').textContent = data.this_month.pledges;
    document.getElementById('statMonthAmount').textContent = formatCurrency(data.this_month.amount);
    document.getElementById('statUniqueDonors').textContent = data.donors.unique_count;
  } catch (error) {
    showToast(error.message, 'error');
  }
}

function loadProfile() {
  const user = auth.getUser();
  if (user) {
    document.getElementById('profileName').textContent = user.name || '-';
    document.getElementById('profilePhone').textContent = user.phone || '-';
    document.getElementById('profileRole').textContent = user.role || '-';
  }
}

function getStatusBadge(status) {
  const badges = { approved: 'success', pending: 'pending', rejected: 'danger' };
  return badges[status] || 'info';
}

function updatePagination(containerId, pagination, loadFn) {
  const container = document.getElementById(containerId);
  if (!container || !pagination || pagination.total_pages <= 1) {
    if (container) container.innerHTML = '';
    return;
  }
  
  const { page, total_pages, has_more } = pagination;
  container.innerHTML = `
    <div class="pagination-controls">
      <button class="pwa-btn pwa-btn-text" ${page <= 1 ? 'disabled' : ''} data-page="${page - 1}">← Prev</button>
      <span class="pagination-info">${page} / ${total_pages}</span>
      <button class="pwa-btn pwa-btn-text" ${!has_more ? 'disabled' : ''} data-page="${page + 1}">Next →</button>
    </div>
  `;
  
  container.querySelectorAll('button[data-page]').forEach(btn => {
    btn.addEventListener('click', () => loadFn(parseInt(btn.dataset.page, 10)));
  });
}

document.addEventListener('DOMContentLoaded', init);

