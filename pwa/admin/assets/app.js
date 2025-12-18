/**
 * Admin PWA Application
 */

const auth = new AuthManager(api, 'admin');
const installTracker = new InstallTracker(api);

let currentView = 'dashboard';
let donorsPage = 1;
let searchQuery = '';
let searchTimeout = null;

async function init() {
  registerServiceWorker();
  
  if (!auth.requireAuth('login.html')) return;
  
  const user = auth.getUser();
  if (!user || user.role !== 'admin') {
    auth.logout();
    window.location.href = 'login.html';
    return;
  }
  
  await installTracker.checkPendingInstallLog();
  
  setupNavigation();
  setupSearch();
  setupLogout();
  
  await loadDashboard();
  
  document.getElementById('welcomeText').textContent = `${user.name || 'Administrator'}`;
  
  if (shouldShowInstallBanner() && installTracker.canInstall()) {
    showInstallBanner(installTracker);
  }
  
  handleHashChange();
  window.addEventListener('hashchange', handleHashChange);
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
  
  if (view === 'donors') loadDonors();
  else if (view === 'stats') loadStats();
  else if (view === 'profile') loadProfile();
  
  history.replaceState(null, '', `#${view}`);
}

function handleHashChange() {
  const hash = window.location.hash.slice(1) || 'dashboard';
  const validViews = ['dashboard', 'donors', 'stats', 'profile'];
  if (validViews.includes(hash)) navigateTo(hash);
}

function setupSearch() {
  const searchInput = document.getElementById('donorSearch');
  if (searchInput) {
    searchInput.addEventListener('input', (e) => {
      clearTimeout(searchTimeout);
      searchTimeout = setTimeout(() => {
        searchQuery = e.target.value.trim();
        donorsPage = 1;
        loadDonors();
      }, 300);
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
    const response = await api.get('/admin/dashboard.php');
    if (!response.success) throw new Error(response.error?.message);
    
    const data = response.data;
    
    // Overview
    document.getElementById('totalDonors').textContent = data.overview.total_donors;
    document.getElementById('totalPledged').textContent = formatCurrency(data.overview.total_pledged);
    document.getElementById('totalCollected').textContent = formatCurrency(data.overview.total_collected);
    document.getElementById('collectionRate').textContent = `${data.overview.collection_rate}%`;
    
    // Pending
    if (data.pending_approvals.total > 0) {
      document.getElementById('pendingAlert').style.display = 'block';
      document.getElementById('pendingCount').textContent = data.pending_approvals.total;
      document.getElementById('pendingPledges').textContent = data.pending_approvals.pledges;
      document.getElementById('pendingPayments').textContent = data.pending_approvals.payments;
    }
    
    // Today
    document.getElementById('todayPledges').textContent = data.today.pledges;
    document.getElementById('todayPayments').textContent = data.today.payments;
    document.getElementById('todayCollected').textContent = formatCurrency(data.today.collected);
    
    // PWA Installations
    document.getElementById('pwaTotal').textContent = data.pwa_installations.total;
    
    const breakdown = document.getElementById('pwaBreakdown');
    if (data.pwa_installations.by_type && Object.keys(data.pwa_installations.by_type).length > 0) {
      breakdown.innerHTML = Object.entries(data.pwa_installations.by_type).map(([key, count]) => {
        const [type, device] = key.split('_');
        return `<div class="install-item"><span>${type}</span><span>${device}: ${count}</span></div>`;
      }).join('');
    } else {
      breakdown.innerHTML = '<div class="install-item"><span>No installations yet</span></div>';
    }
    
    // Recent
    const list = document.getElementById('recentList');
    if (data.recent_registrations && data.recent_registrations.length > 0) {
      list.innerHTML = data.recent_registrations.map(r => `
        <li class="pwa-list-item">
          <div class="pwa-list-item-content">
            <div class="pwa-list-item-title">${escapeHtml(r.donor_name)}</div>
            <div class="pwa-list-item-subtitle">${formatCurrency(r.amount)} · ${r.registered_by || 'Unknown'}</div>
          </div>
          <span class="pwa-badge pwa-badge-${getStatusBadge(r.status)}">${r.status}</span>
        </li>
      `).join('');
    } else {
      list.innerHTML = '<li class="pwa-list-item empty-state">No recent registrations</li>';
    }
  } catch (error) {
    showToast(error.message, 'error');
  }
}

async function loadDonors(page = 1) {
  const list = document.getElementById('donorsList');
  list.innerHTML = '<li class="pwa-list-item empty-state">Loading...</li>';
  
  try {
    const params = { page, per_page: 20 };
    if (searchQuery) params.search = searchQuery;
    
    const response = await api.get('/admin/donors.php', params);
    if (!response.success) throw new Error(response.error?.message);
    
    const donors = response.data;
    
    if (donors.length > 0) {
      list.innerHTML = donors.map(d => `
        <li class="pwa-list-item">
          <div class="pwa-list-item-content">
            <div class="pwa-list-item-title">${escapeHtml(d.name)}</div>
            <div class="pwa-list-item-subtitle">${d.phone} · Balance: ${formatCurrency(d.balance)}</div>
          </div>
          <span class="pwa-badge pwa-badge-${getStatusBadge(d.payment_status)}">${d.payment_status || 'N/A'}</span>
        </li>
      `).join('');
      
      updatePagination('donorsPagination', response.pagination, loadDonors);
    } else {
      list.innerHTML = '<li class="pwa-list-item empty-state">No donors found</li>';
    }
    
    donorsPage = page;
  } catch (error) {
    list.innerHTML = '<li class="pwa-list-item empty-state">Failed to load</li>';
    showToast(error.message, 'error');
  }
}

async function loadStats() {
  try {
    const response = await api.get('/admin/dashboard.php');
    if (!response.success) throw new Error(response.error?.message);
    
    const data = response.data;
    
    document.getElementById('monthPledged').textContent = formatCurrency(data.this_month.pledged);
    document.getElementById('monthCollected').textContent = formatCurrency(data.this_month.collected);
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
  const badges = {
    approved: 'success', pending: 'pending', rejected: 'danger',
    paid: 'success', partial: 'warning', unpaid: 'danger', overpaid: 'info'
  };
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

