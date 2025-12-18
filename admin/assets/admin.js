// Modern Admin Dashboard JavaScript

// PWA Support
(function() {
  // Add manifest link if not already present
  if (!document.querySelector('link[rel="manifest"]')) {
    const manifestLink = document.createElement('link');
    manifestLink.rel = 'manifest';
    manifestLink.href = '/admin/manifest.json';
    document.head.appendChild(manifestLink);
  }
  
  // Add theme-color meta if not present
  if (!document.querySelector('meta[name="theme-color"]')) {
    const themeMeta = document.createElement('meta');
    themeMeta.name = 'theme-color';
    themeMeta.content = '#0a6286';
    document.head.appendChild(themeMeta);
  }
  
  // Add apple-mobile-web-app-capable
  if (!document.querySelector('meta[name="apple-mobile-web-app-capable"]')) {
    const appleMeta = document.createElement('meta');
    appleMeta.name = 'apple-mobile-web-app-capable';
    appleMeta.content = 'yes';
    document.head.appendChild(appleMeta);
  }
  
  // Register Service Worker
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
      navigator.serviceWorker.register('/admin/sw.js')
        .then(function(reg) {
          console.log('[PWA] Service Worker registered');
        })
        .catch(function(err) {
          console.log('[PWA] SW registration failed:', err);
        });
    });
  }
})();

document.addEventListener('DOMContentLoaded', function() {
  // Initialize Bootstrap dropdowns
  if (typeof bootstrap !== 'undefined') {
    const dropdownElementList = [].slice.call(document.querySelectorAll('[data-bs-toggle="dropdown"]'));
    dropdownElementList.map(function (dropdownToggleEl) {
      return new bootstrap.Dropdown(dropdownToggleEl);
    });
  }
  
  // Sidebar Toggle Functions
  const body = document.body;
  
  // Toggle Sidebar (Mobile & Desktop)
  window.toggleSidebar = function() {
    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.querySelector('.sidebar-overlay');
    if (!sidebar || !sidebarOverlay) return;
    if (window.innerWidth <= 991.98) {
      // Mobile: slide in/out
      sidebar.classList.toggle('active');
      sidebarOverlay.classList.toggle('active');
      body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
    } else {
      // Desktop: collapse/expand
      body.classList.toggle('sidebar-collapsed');
      
      // Save state to localStorage
      const isCollapsed = body.classList.contains('sidebar-collapsed');
      localStorage.setItem('sidebarCollapsed', isCollapsed);
    }
  };
  
  // Default collapsed on desktop; honor saved preference if present
  if (window.innerWidth > 991.98) {
    const stored = localStorage.getItem('sidebarCollapsed');
    const isCollapsed = stored === null ? true : (stored === 'true');
    if (isCollapsed) {
      body.classList.add('sidebar-collapsed');
    } else {
      body.classList.remove('sidebar-collapsed');
    }
  }
  
  // Close mobile sidebar when clicking overlay
  const sidebarOverlayInit = document.querySelector('.sidebar-overlay');
  if (sidebarOverlayInit) {
    sidebarOverlayInit.addEventListener('click', function() {
      const sidebar = document.getElementById('sidebar');
      const sidebarOverlay = document.querySelector('.sidebar-overlay');
      if (!sidebar || !sidebarOverlay) return;
      sidebar.classList.remove('active');
      sidebarOverlay.classList.remove('active');
      body.style.overflow = '';
    });
  }
  
  // Handle window resize
  let resizeTimer;
  window.addEventListener('resize', function() {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function() {
      if (window.innerWidth > 991.98) {
        // Desktop view: remove mobile classes
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.querySelector('.sidebar-overlay');
        if (sidebar) sidebar.classList.remove('active');
        if (sidebarOverlay) sidebarOverlay.classList.remove('active');
        body.style.overflow = '';
      } else {
        // Mobile view: remove desktop collapse class
        body.classList.remove('sidebar-collapsed');
      }
    }, 250);
  });
  
  // Add active class to current page nav item
  const currentPath = window.location.pathname;
  const navLinks = document.querySelectorAll('.nav-link');
  navLinks.forEach(link => {
    if (link.getAttribute('href') && currentPath.includes(link.getAttribute('href'))) {
      link.classList.add('active');
    }
  });
  
  // Smooth scroll for anchor links
  document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
      const href = this.getAttribute('href');
      // Only handle if href still starts with # (anchor link)
      if (!href || !href.startsWith('#')) {
        return; // Let the browser handle normal links
      }
      e.preventDefault();
      const target = document.querySelector(href);
      if (target) {
        target.scrollIntoView({
          behavior: 'smooth',
          block: 'start'
        });
      }
    });
  });
  
  // Optional loading states (opt-in only)
  // Usage: add class 'btn-loading' or attribute 'data-loading' to a button to enable
  document.querySelectorAll('.btn-loading, [data-loading]').forEach(button => {
    button.addEventListener('click', function() {
      const originalText = this.innerHTML;
      this.dataset.originalText = originalText;
      const text = this.getAttribute('data-loading-text') || 'Loading...';
      this.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>' + text;
      this.disabled = true;
      // Do not auto re-enable here; server-side navigation (PRG) will update the UI
    });
  });
  
  // Keyboard shortcuts
  document.addEventListener('keydown', function(e) {
    // Ctrl/Cmd + B: Toggle sidebar
    if ((e.ctrlKey || e.metaKey) && e.key === 'b') {
      e.preventDefault();
      toggleSidebar();
    }
    
    // Escape: Close mobile sidebar
    if (e.key === 'Escape' && window.innerWidth <= 991.98) {
      const sidebar = document.getElementById('sidebar');
      const sidebarOverlay = document.querySelector('.sidebar-overlay');
      if (sidebar) sidebar.classList.remove('active');
      if (sidebarOverlay) sidebarOverlay.classList.remove('active');
      body.style.overflow = '';
    }
  });
  
  // Notification counter animation
  const notificationBadge = document.querySelector('.notification-badge');
  if (notificationBadge) {
    setInterval(() => {
      notificationBadge.classList.add('pulse');
      setTimeout(() => notificationBadge.classList.remove('pulse'), 1000);
    }, 5000);
  }
  
  // Add pulse animation CSS
  const style = document.createElement('style');
  style.textContent = `
    @keyframes pulse {
      0% { transform: scale(1); }
      50% { transform: scale(1.2); }
      100% { transform: scale(1); }
    }
    .pulse {
      animation: pulse 0.5s ease-in-out;
    }
  `;
  document.head.appendChild(style);
  
  // Initialize tooltips if Bootstrap is available
  if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
      return new bootstrap.Tooltip(tooltipTriggerEl);
    });
  }
  
  // Global keyboard shortcuts
  document.addEventListener('keydown', function(e) {
    // Ctrl+K to open command palette
    if (e.ctrlKey && e.key === 'k') {
      e.preventDefault();
      openCommandPalette();
    }
    
    // Escape to close command palette
    if (e.key === 'Escape') {
      closeCommandPalette();
    }
  });
});

// Command Palette System
let commandPalette = null;
let commandPaletteData = [
  { title: 'Dashboard', description: 'View main dashboard', url: '../dashboard/', icon: 'fas fa-tachometer-alt', category: 'Navigation' },
  { title: 'Approvals', description: 'Manage pending approvals', url: '../approvals/', icon: 'fas fa-check-circle', category: 'Navigation' },
  { title: 'Pledges', description: 'View all pledges', url: '../pledges/', icon: 'fas fa-hand-holding-usd', category: 'Navigation' },
  { title: 'Members', description: 'Manage members', url: '../members/', icon: 'fas fa-users', category: 'Navigation' },
  { title: 'Payments', description: 'View payment history', url: '../payments/', icon: 'fas fa-credit-card', category: 'Navigation' },
  { title: 'Reports', description: 'Generate reports', url: '../reports/', icon: 'fas fa-chart-bar', category: 'Navigation' },
  { title: 'Projector Control', description: 'Control projector display', url: '../projector/', icon: 'fas fa-tv', category: 'Navigation' },
  { title: 'Audit Logs', description: 'View system logs', url: '../audit/', icon: 'fas fa-history', category: 'Navigation' },
  { title: 'Settings', description: 'System configuration', url: '../settings/', icon: 'fas fa-cog', category: 'Navigation' },
  { title: 'Notifications', description: 'View notifications', url: '../notifications/', icon: 'fas fa-bell', category: 'Navigation' },
  { title: 'Profile', description: 'Edit your profile', url: '../profile/', icon: 'fas fa-user-circle', category: 'Navigation' },
  { title: 'Logout', description: 'Sign out of the system', url: '../logout.php', icon: 'fas fa-sign-out-alt', category: 'Account' },
  { title: 'Recalculate Totals', description: 'Refresh fundraising totals', action: 'recalculateFromPalette', icon: 'fas fa-calculator', category: 'Actions' },
  { title: 'Clear Cache', description: 'Clear system cache', action: 'clearCacheFromPalette', icon: 'fas fa-broom', category: 'Actions' },
  { title: 'Backup Database', description: 'Download database backup', action: 'backupFromPalette', icon: 'fas fa-database', category: 'Actions' }
];

function openCommandPalette() {
  if (commandPalette) return;
  
  // Create command palette HTML
  commandPalette = document.createElement('div');
  commandPalette.className = 'command-palette-overlay';
  commandPalette.innerHTML = `
    <div class="command-palette">
      <div class="command-search">
        <i class="fas fa-search"></i>
        <input type="text" placeholder="Type a command or search..." id="commandInput" autocomplete="off">
        <div class="command-shortcut">Ctrl+K</div>
      </div>
      <div class="command-results" id="commandResults">
        <!-- Results will be populated here -->
      </div>
      <div class="command-footer">
        <div class="command-hint">
          <span><kbd>↵</kbd> to select</span>
          <span><kbd>↑↓</kbd> to navigate</span>
          <span><kbd>Esc</kbd> to close</span>
        </div>
      </div>
    </div>
  `;
  
  document.body.appendChild(commandPalette);
  
  // Add event listeners
  const input = commandPalette.querySelector('#commandInput');
  input.addEventListener('input', filterCommands);
  input.addEventListener('keydown', handleCommandNavigation);
  
  // Click outside to close
  commandPalette.addEventListener('click', function(e) {
    if (e.target === commandPalette) {
      closeCommandPalette();
    }
  });
  
  // Show all commands initially
  filterCommands();
  
  // Focus input
  setTimeout(() => input.focus(), 100);
}

function closeCommandPalette() {
  if (commandPalette) {
    commandPalette.remove();
    commandPalette = null;
  }
}

function filterCommands() {
  const input = document.getElementById('commandInput');
  const results = document.getElementById('commandResults');
  const query = input.value.toLowerCase();
  
  let filteredCommands = commandPaletteData;
  if (query) {
    filteredCommands = commandPaletteData.filter(cmd => 
      cmd.title.toLowerCase().includes(query) || 
      cmd.description.toLowerCase().includes(query) ||
      cmd.category.toLowerCase().includes(query)
    );
  }
  
  // Group by category
  const grouped = {};
  filteredCommands.forEach(cmd => {
    if (!grouped[cmd.category]) grouped[cmd.category] = [];
    grouped[cmd.category].push(cmd);
  });
  
  // Render results
  results.innerHTML = '';
  Object.keys(grouped).forEach(category => {
    const categoryDiv = document.createElement('div');
    categoryDiv.className = 'command-category';
    categoryDiv.innerHTML = `<div class="category-title">${category}</div>`;
    
    grouped[category].forEach((cmd, index) => {
      const cmdDiv = document.createElement('div');
      cmdDiv.className = 'command-item';
      cmdDiv.innerHTML = `
        <div class="command-icon"><i class="${cmd.icon}"></i></div>
        <div class="command-content">
          <div class="command-title">${cmd.title}</div>
          <div class="command-description">${cmd.description}</div>
        </div>
      `;
      
      cmdDiv.addEventListener('click', () => executeCommand(cmd));
      categoryDiv.appendChild(cmdDiv);
    });
    
    results.appendChild(categoryDiv);
  });
  
  // Reset selection
  document.querySelectorAll('.command-item').forEach(item => item.classList.remove('selected'));
  if (document.querySelector('.command-item')) {
    document.querySelector('.command-item').classList.add('selected');
  }
}

function handleCommandNavigation(e) {
  const items = document.querySelectorAll('.command-item');
  const current = document.querySelector('.command-item.selected');
  
  if (e.key === 'ArrowDown') {
    e.preventDefault();
    const next = current?.nextElementSibling || items[0];
    if (next && next.classList.contains('command-item')) {
      current?.classList.remove('selected');
      next.classList.add('selected');
      next.scrollIntoView({ block: 'nearest' });
    }
  } else if (e.key === 'ArrowUp') {
    e.preventDefault();
    const currentIndex = Array.from(items).indexOf(current);
    const prev = currentIndex > 0 ? items[currentIndex - 1] : items[items.length - 1];
    if (prev) {
      current?.classList.remove('selected');
      prev.classList.add('selected');
      prev.scrollIntoView({ block: 'nearest' });
    }
  } else if (e.key === 'Enter') {
    e.preventDefault();
    if (current) {
      const commandData = getCommandFromElement(current);
      executeCommand(commandData);
    }
  }
}

function getCommandFromElement(element) {
  const title = element.querySelector('.command-title').textContent;
  return commandPaletteData.find(cmd => cmd.title === title);
}

function executeCommand(command) {
  closeCommandPalette();
  
  if (command.url) {
    window.location.href = command.url;
  } else if (command.action) {
    // Execute custom actions
    switch (command.action) {
      case 'recalculateFromPalette':
        if (typeof recalculateTotals === 'function') {
          recalculateTotals();
        } else {
          alert('Recalculate totals functionality');
        }
        break;
      case 'clearCacheFromPalette':
        if (typeof clearCache === 'function') {
          clearCache();
        } else {
          alert('Clear cache functionality');
        }
        break;
      case 'backupFromPalette':
        if (typeof backupDatabase === 'function') {
          backupDatabase();
        } else {
          alert('Backup database functionality');
        }
        break;
    }
  }
}

// Load message notifications script
(function() {
    if (!window.location.pathname.includes('/messages/')) {
        const script = document.createElement('script');
        script.src = '../../shared/js/message-notifications.js?v=' + Date.now();
        script.async = true;
        document.head.appendChild(script);
    }
})();
