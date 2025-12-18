// Registrar Panel JavaScript - Mobile First

// ============ PWA Support - Force Install ============
(function() {
  // Add manifest link
  if (!document.querySelector('link[rel="manifest"]')) {
    const link = document.createElement('link');
    link.rel = 'manifest';
    link.href = '/registrar/manifest.json';
    document.head.appendChild(link);
  }
  
  // Add meta tags
  if (!document.querySelector('meta[name="theme-color"]')) {
    const meta = document.createElement('meta');
    meta.name = 'theme-color';
    meta.content = '#28a745';
    document.head.appendChild(meta);
  }
  
  if (!document.querySelector('meta[name="apple-mobile-web-app-capable"]')) {
    const meta = document.createElement('meta');
    meta.name = 'apple-mobile-web-app-capable';
    meta.content = 'yes';
    document.head.appendChild(meta);
  }
  
  // Register Service Worker
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/registrar/sw.js')
      .then(function(reg) { console.log('[PWA] SW registered'); })
      .catch(function(err) { console.log('[PWA] SW failed:', err); });
  }
  
  // PWA Install State
  let deferredPrompt = null;
  let installModalShown = false;
  
  // Check if installed
  function isAppInstalled() {
    return window.matchMedia('(display-mode: standalone)').matches || 
           window.navigator.standalone === true;
  }
  
  // Check if install was dismissed today
  function wasDismissedToday() {
    const dismissed = localStorage.getItem('pwa_install_dismissed');
    if (!dismissed) return false;
    const today = new Date().toDateString();
    return dismissed === today;
  }
  
  // Capture the install prompt
  window.addEventListener('beforeinstallprompt', function(e) {
    e.preventDefault();
    deferredPrompt = e;
    console.log('[PWA] ✅ Install prompt captured!');
    
    // Show modal immediately when prompt is available
    if (!isAppInstalled() && !installModalShown && !wasDismissedToday()) {
      showInstallModal();
    }
  });
  
  window.addEventListener('appinstalled', function() {
    console.log('[PWA] ✅ App installed successfully!');
    hideInstallModal();
    localStorage.removeItem('pwa_install_dismissed');
  });
  
  // Create and show the blocking install modal
  function showInstallModal() {
    if (isAppInstalled() || document.getElementById('pwaInstallModal')) return;
    installModalShown = true;
    
    const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent);
    const hasPrompt = deferredPrompt !== null;
    
    // Create modal
    const modal = document.createElement('div');
    modal.id = 'pwaInstallModal';
    modal.innerHTML = `
      <div class="pwa-modal-backdrop"></div>
      <div class="pwa-modal-content">
        <div class="pwa-modal-icon">📱</div>
        <h2 class="pwa-modal-title">Install Registrar App</h2>
        <p class="pwa-modal-text">
          For the best experience, please install this app on your device.
        </p>
        
        <ul class="pwa-benefits">
          <li>✓ Quick access from home screen</li>
          <li>✓ Works offline</li>
          <li>✓ Faster loading</li>
          <li>✓ Full screen experience</li>
        </ul>
        
        ${isIOS ? `
          <div class="pwa-ios-steps">
            <p><strong>To install on your iPhone/iPad:</strong></p>
            <div class="pwa-step">
              <span class="pwa-step-num">1</span>
              <span>Tap the <strong>Share</strong> button <span class="share-icon">⬆️</span> at the bottom</span>
            </div>
            <div class="pwa-step">
              <span class="pwa-step-num">2</span>
              <span>Scroll and tap <strong>"Add to Home Screen"</strong></span>
            </div>
            <div class="pwa-step">
              <span class="pwa-step-num">3</span>
              <span>Tap <strong>"Add"</strong> in the top right</span>
            </div>
          </div>
          <button class="pwa-btn pwa-btn-primary" onclick="dismissInstallModal()">
            I'll do it now
          </button>
        ` : hasPrompt ? `
          <button class="pwa-btn pwa-btn-primary" onclick="triggerInstall()">
            <i class="fas fa-download"></i> Install Now
          </button>
        ` : `
          <div class="pwa-error-box">
            <p><strong>⚠️ Install not available</strong></p>
            <p>This could be because:</p>
            <ul>
              <li>Site is not on HTTPS</li>
              <li>Browser doesn't support installation</li>
              <li>App is already installed</li>
            </ul>
            <p>Try using <strong>Chrome</strong> or <strong>Edge</strong> browser.</p>
          </div>
          <button class="pwa-btn pwa-btn-secondary" onclick="dismissInstallModal()">
            Continue anyway
          </button>
        `}
        
        <button class="pwa-btn pwa-btn-text" onclick="dismissInstallModal()">
          Remind me later
        </button>
      </div>
    `;
    
    // Add styles
    const styles = document.createElement('style');
    styles.id = 'pwaModalStyles';
    styles.textContent = `
      .pwa-modal-backdrop {
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.7);
        z-index: 99998;
        backdrop-filter: blur(4px);
      }
      .pwa-modal-content {
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        background: white;
        border-radius: 20px;
        padding: 2rem;
        max-width: 380px;
        width: 90%;
        z-index: 99999;
        text-align: center;
        box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        animation: modalSlideIn 0.3s ease;
      }
      @keyframes modalSlideIn {
        from { opacity: 0; transform: translate(-50%, -50%) scale(0.9); }
        to { opacity: 1; transform: translate(-50%, -50%) scale(1); }
      }
      .pwa-modal-icon {
        font-size: 4rem;
        margin-bottom: 1rem;
      }
      .pwa-modal-title {
        margin: 0 0 0.5rem;
        font-size: 1.5rem;
        color: #28a745;
      }
      .pwa-modal-text {
        color: #666;
        margin-bottom: 1rem;
      }
      .pwa-benefits {
        list-style: none;
        padding: 0;
        margin: 0 0 1.5rem;
        background: #f8f9fa;
        border-radius: 12px;
        padding: 1rem;
        text-align: left;
      }
      .pwa-benefits li {
        padding: 0.5rem 0;
        color: #333;
        border-bottom: 1px solid #e9ecef;
      }
      .pwa-benefits li:last-child { border-bottom: none; }
      .pwa-ios-steps {
        background: #e8f5e9;
        border-radius: 12px;
        padding: 1rem;
        margin-bottom: 1rem;
        text-align: left;
      }
      .pwa-ios-steps p { margin: 0 0 0.75rem; color: #2e7d32; }
      .pwa-step {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.5rem 0;
      }
      .pwa-step-num {
        width: 28px;
        height: 28px;
        background: #28a745;
        color: white;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        flex-shrink: 0;
      }
      .share-icon {
        display: inline-block;
        background: #007AFF;
        color: white;
        width: 22px;
        height: 22px;
        border-radius: 4px;
        text-align: center;
        line-height: 22px;
        font-size: 12px;
      }
      .pwa-error-box {
        background: #fff3cd;
        border: 1px solid #ffc107;
        border-radius: 12px;
        padding: 1rem;
        margin-bottom: 1rem;
        text-align: left;
        font-size: 0.9rem;
      }
      .pwa-error-box p { margin: 0 0 0.5rem; }
      .pwa-error-box ul { margin: 0.5rem 0; padding-left: 1.25rem; }
      .pwa-btn {
        display: block;
        width: 100%;
        padding: 1rem;
        border: none;
        border-radius: 12px;
        font-size: 1rem;
        font-weight: 600;
        cursor: pointer;
        margin-bottom: 0.75rem;
        transition: all 0.2s;
      }
      .pwa-btn-primary {
        background: linear-gradient(135deg, #28a745 0%, #1e7b34 100%);
        color: white;
        box-shadow: 0 4px 15px rgba(40, 167, 69, 0.4);
      }
      .pwa-btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(40, 167, 69, 0.5);
      }
      .pwa-btn-secondary {
        background: #6c757d;
        color: white;
      }
      .pwa-btn-text {
        background: transparent;
        color: #6c757d;
        padding: 0.5rem;
      }
      @media (display-mode: standalone) {
        #pwaInstallModal { display: none !important; }
      }
    `;
    
    document.head.appendChild(styles);
    document.body.appendChild(modal);
  }
  
  // Trigger native install
  window.triggerInstall = async function() {
    if (!deferredPrompt) {
      alert('Install prompt not available. Please try refreshing the page.');
      return;
    }
    
    try {
      deferredPrompt.prompt();
      const { outcome } = await deferredPrompt.userChoice;
      
      if (outcome === 'accepted') {
        console.log('[PWA] User accepted install');
        hideInstallModal();
      } else {
        console.log('[PWA] User dismissed install');
      }
      deferredPrompt = null;
    } catch (err) {
      console.error('[PWA] Install error:', err);
      alert('Installation failed. Please try again.');
    }
  };
  
  // Dismiss modal
  window.dismissInstallModal = function() {
    localStorage.setItem('pwa_install_dismissed', new Date().toDateString());
    hideInstallModal();
  };
  
  // Hide modal
  function hideInstallModal() {
    const modal = document.getElementById('pwaInstallModal');
    if (modal) modal.remove();
    const styles = document.getElementById('pwaModalStyles');
    if (styles) styles.remove();
  }
  
  // Show modal on page load if not installed
  document.addEventListener('DOMContentLoaded', function() {
    if (!isAppInstalled() && !wasDismissedToday()) {
      // Wait for beforeinstallprompt, then show modal
      setTimeout(function() {
        if (!installModalShown) {
          showInstallModal();
        }
      }, 1500);
    }
  });
})();
// ============ End PWA Support ============

// Utility function for phone normalization (used in real-time validation)
function normalizeUkPhone(phone) {
    let digits = phone.replace(/[^0-9+]/g, '');
    if (digits.startsWith('+44')) {
        digits = '0' + digits.slice(3);
    }
    return digits;
}

// DOM ready
document.addEventListener('DOMContentLoaded', function() {
    // Initialize components
    initSidebar();
    initTooltips();
    initFormValidation();
    initRegistrationForm();
});

// Sidebar functionality
function initSidebar() {
    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebarClose = document.getElementById('sidebarClose');
    const desktopSidebarToggle = document.getElementById('desktopSidebarToggle');
    const appContent = document.querySelector('.app-content');

    // If there is no sidebar on this page, bail out early.
    if (!sidebar) {
        return;
    }

    // Toggle sidebar (mobile)
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function() {
            sidebar.classList.add('show');
            sidebar.classList.remove('collapsed'); // Remove collapsed to show full text
            if (sidebarOverlay) {
                sidebarOverlay.classList.add('show');
            }
            document.body.style.overflow = 'hidden';
        });
    }

    // Ensure collapsed by default on desktop
    if (sidebar && window.matchMedia('(min-width: 768px)').matches) {
        sidebar.classList.add('collapsed');
        if (appContent) { appContent.classList.add('collapsed'); }
    }

    // Toggle sidebar (desktop)
    if (desktopSidebarToggle) {
        desktopSidebarToggle.addEventListener('click', function() {
            sidebar.classList.toggle('collapsed');
            appContent.classList.toggle('collapsed');
        });
    }
    
    // Close sidebar
    function closeSidebar() {
        sidebar.classList.remove('show');
        if (sidebarOverlay) {
            sidebarOverlay.classList.remove('show');
        }
        document.body.style.overflow = '';
    }
    
    if (sidebarClose) {
        sidebarClose.addEventListener('click', closeSidebar);
    }
    
    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', closeSidebar);
    }
    
    // Close sidebar on escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && sidebar.classList.contains('show')) {
            closeSidebar();
        }
    });
}

function showRepeatDonorModal(donorData) {
    const modal = new bootstrap.Modal(document.getElementById('repeatDonorModal'));
    const donorName = donorData.donor_name || 'Returning Donor';
    const donorPhone = donorData.normalized || 'N/A';
    
    // Populate donor info
    document.getElementById('modalDonorName').textContent = donorName;
    document.getElementById('modalDonorPhone').textContent = donorPhone;
    
    // Populate recent donations table
    const tbody = document.getElementById('donationsTableBody');
    tbody.innerHTML = ''; // Clear existing rows
    
    if (donorData.recent_donations && donorData.recent_donations.length > 0) {
        donorData.recent_donations.forEach(donation => {
            const row = document.createElement('tr');
            const date = new Date(donation.date);
            const formattedDate = date.toLocaleDateString('en-GB', { year: 'numeric', month: 'short', day: 'numeric' });
            const statusBadge = donation.status === 'approved' ? 
                '<span class="badge bg-success">Approved</span>' : 
                '<span class="badge bg-warning">Pending</span>';
            
            row.innerHTML = `
                <td><span class="badge bg-${donation.type === 'pledge' ? 'info' : 'primary'}">${donation.type === 'pledge' ? 'Pledge' : 'Payment'}</span></td>
                <td>£${parseFloat(donation.amount).toFixed(2)}</td>
                <td>${statusBadge}</td>
                <td>${formattedDate}</td>
            `;
            tbody.appendChild(row);
        });
    } else {
        const row = document.createElement('tr');
        row.innerHTML = '<td colspan="4" class="text-center text-muted">No previous donations found</td>';
        tbody.appendChild(row);
    }
    
    modal.show();
}

function initRegistrationForm() {
    const form = document.querySelector('.registration-form');
    if (!form) return;

    // 1. Generate and set a client-side UUID to prevent double submission
    const uuidv4 = () => {
        if (self.crypto && typeof self.crypto.randomUUID === 'function') {
            try { return self.crypto.randomUUID(); } catch (e) {}
        }
        // Fallback UUID v4 generator
        const bytes = new Uint8Array(16);
        if (self.crypto && self.crypto.getRandomValues) {
            self.crypto.getRandomValues(bytes);
        } else {
            for (let i = 0; i < 16; i++) bytes[i] = Math.floor(Math.random() * 256);
        }
        bytes[6] = (bytes[6] & 0x0f) | 0x40; // version 4
        bytes[8] = (bytes[8] & 0x3f) | 0x80; // variant
        const toHex = (n) => n.toString(16).padStart(2, '0');
        const b = Array.from(bytes, toHex);
        return `${b[0]}${b[1]}${b[2]}${b[3]}-${b[4]}${b[5]}-${b[6]}${b[7]}-${b[8]}${b[9]}-${b[10]}${b[11]}${b[12]}${b[13]}${b[14]}${b[15]}`;
    };
    let clientUuid = uuidv4();
    let uuidInput = form.querySelector('input[name="client_uuid"]');
    if (uuidInput) {
        uuidInput.value = clientUuid;
    }

    // 2. Validation + duplicate check + loading state
    let submitting = false;
    form.addEventListener('submit', async function(e) {
        if (submitting) return; // guard double click
        e.preventDefault();
        e.stopPropagation();

        const submitBtn = form.querySelector('.btn-submit');
        const phoneEl = document.getElementById('phone');
        const anonEl = document.getElementById('anonymous');
        const typeEl = form.querySelector('input[name="type"]:checked');
        const typeVal = typeEl ? typeEl.value : 'pledge';
        const rawPhone = (phoneEl?.value || '').trim();
        const isAnon = !!(anonEl && anonEl.checked);
        const additionalDonationCheckbox = document.getElementById('additional_donation');

        const normalizeUk = (v) => {
            let s = (v||'').replace(/[^0-9+]/g, '');
            if (s.startsWith('+44')) s = '0' + s.slice(3);
            return s;
        };
        

        // Phone format validation when required
        let normalized = rawPhone;
        if (typeVal === 'pledge' || (typeVal === 'paid' && !isAnon)) {
            normalized = normalizeUk(rawPhone);
            if (!/^07\d{9}$/.test(normalized)) {
                alert('Please enter a valid UK mobile number starting with 07.');
                return;
            }
        }

        // If additional_donation checkbox is already checked and visible, skip duplicate check
        const additionalDiv = document.getElementById('additionalDonationDiv');
        if (additionalDiv && !additionalDiv.classList.contains('d-none') && additionalDonationCheckbox && additionalDonationCheckbox.checked) {
            // User already confirmed they want to make another donation, proceed directly
            submitting = true;
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = `<i class="fas fa-spinner fa-spin me-2"></i>Processing...`;
            }
            form.submit();
            return; // Exit early, don't run duplicate check
        }

        // Duplicate check via API (only if we have a phone to check and checkbox is not already approved)
        if (normalized) {
            try {
                const res = await fetch(`../../api/check_donor.php?phone=${encodeURIComponent(normalized)}`);
                const data = await res.json();
                const hasPreviousDonations = (data.pledges?.pending > 0 || data.pledges?.approved > 0 || data.payments?.pending > 0 || data.payments?.approved > 0);
                
                if (hasPreviousDonations) {
                    // Show the repeat donor modal with their history
                    showRepeatDonorModal(data);
                    
                    // Show the additional donation checkbox
                    const additionalDonationDiv = document.getElementById('additionalDonationDiv');
                    if (additionalDonationDiv) {
                        additionalDonationDiv.classList.remove('d-none');
                    }
                    return; // Stop submission
                } else {
                    // No previous donations, hide the checkbox
                    const additionalDonationDiv = document.getElementById('additionalDonationDiv');
                    if (additionalDonationDiv) {
                        additionalDonationDiv.classList.add('d-none');
                        if (additionalDonationCheckbox) additionalDonationCheckbox.checked = false;
                    }
                }
            } catch (err) {
                // If API fails, still proceed; server will enforce duplicate rules
                console.warn('Duplicate check error:', err);
            }
        }

        // Check if additional_donation checkbox is required but not checked
        if (additionalDonationCheckbox && !additionalDonationCheckbox.classList.contains('d-none')) {
            const additionalDonationDiv = document.getElementById('additionalDonationDiv');
            if (!additionalDonationDiv?.classList.contains('d-none') && !additionalDonationCheckbox.checked) {
                alert('Please check the "This donor wants to make another donation" checkbox to continue.');
                return;
            }
        }

        // Passed checks -> submit
        submitting = true;
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = `<i class="fas fa-spinner fa-spin me-2"></i>Processing...`;
        }
        form.submit();
    });

    // 3. Reset form state if user navigates back (e.g., browser back button)
    window.addEventListener('pageshow', function(event) {
        if (event.persisted) {
            const submitBtn = form.querySelector('.btn-submit');
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = `<i class="fas fa-save"></i> Register Donation`;
            }
            // Reset UUID on back navigation to allow a new submission
            clientUuid = uuidv4();
            if (uuidInput) {
                uuidInput.value = clientUuid;
            }
        }
    });
}

// Initialize Bootstrap tooltips
function initTooltips() {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
}

// Form validation
function initFormValidation() {
    const forms = document.querySelectorAll('.needs-validation');
    
    Array.from(forms).forEach(form => {
        form.addEventListener('submit', event => {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            
            form.classList.add('was-validated');
        }, false);
    });
    
    // Real-time validation for phone number (UK mobile format)
    const phoneField = document.getElementById('phone');
    if (phoneField) {
        phoneField.addEventListener('input', function() {
            const phone = normalizeUkPhone(this.value);
            const isValid = /^07\d{9}$/.test(phone);
            const isRequired = this.required;
            
            if (isRequired && this.value.trim() === '') {
                this.classList.remove('is-valid', 'is-invalid');
            } else if (this.value.trim() !== '') {
                if (isValid) {
                    this.classList.remove('is-invalid');
                    this.classList.add('is-valid');
                } else {
                    this.classList.remove('is-valid');
                    this.classList.add('is-invalid');
                }
            }
        });
    }

    // Name validation
    const nameField = document.getElementById('name');
    if (nameField) {
        nameField.addEventListener('input', function() {
            const isRequired = this.required;
            if (isRequired && this.value.trim() === '') {
                this.classList.remove('is-valid');
                this.classList.add('is-invalid');
            } else if (this.value.trim().length >= 2) {
                this.classList.remove('is-invalid');
                this.classList.add('is-valid');
            } else if (this.value.trim() !== '') {
                this.classList.remove('is-valid');
                this.classList.add('is-invalid');
            } else {
                this.classList.remove('is-valid', 'is-invalid');
            }
        });
    }
}

// Success animation
function showSuccessAnimation(message) {
    const successDiv = document.createElement('div');
    successDiv.className = 'success-animation';
    successDiv.innerHTML = `
        <i class="fas fa-check-circle text-success" style="font-size: 4rem; margin-bottom: 1rem;"></i>
        <h3>${message}</h3>
    `;
    
    document.body.appendChild(successDiv);
    
    setTimeout(() => {
        successDiv.style.opacity = '0';
        setTimeout(() => {
            successDiv.remove();
        }, 300);
    }, 2000);
}

// Format currency
function formatCurrency(amount, currency = 'GBP') {
    return new Intl.NumberFormat('en-GB', {
        style: 'currency',
        currency: currency,
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }).format(amount);
}

// Add ripple effect to buttons
document.addEventListener('click', function(e) {
    const button = e.target.closest('.btn, .quick-amount-btn');
    if (!button) return;
    
    const ripple = document.createElement('span');
    ripple.className = 'ripple';
    button.appendChild(ripple);
    
    const rect = button.getBoundingClientRect();
    const size = Math.max(rect.width, rect.height);
    const x = e.clientX - rect.left - size / 2;
    const y = e.clientY - rect.top - size / 2;
    
    ripple.style.width = ripple.style.height = size + 'px';
    ripple.style.left = x + 'px';
    ripple.style.top = y + 'px';
    
    setTimeout(() => ripple.remove(), 600);
});

// Auto-save form data
function autoSaveForm(formId) {
    const form = document.getElementById(formId);
    if (!form) return;
    
    const saveKey = `registrar_form_${formId}`;
    
    // Load saved data
    const savedData = localStorage.getItem(saveKey);
    if (savedData) {
        try {
            const data = JSON.parse(savedData);
            Object.keys(data).forEach(key => {
                const field = form.elements[key];
                if (field) {
                    if (field.type === 'checkbox' || field.type === 'radio') {
                        field.checked = data[key];
                    } else {
                        field.value = data[key];
                    }
                }
            });
        } catch (e) {
            console.error('Error loading saved form data:', e);
        }
    }
    
    // Save on input
    form.addEventListener('input', function() {
        const data = {};
        Array.from(form.elements).forEach(field => {
            if (field.name && !field.disabled) {
                if (field.type === 'checkbox' || field.type === 'radio') {
                    data[field.name] = field.checked;
                } else {
                    data[field.name] = field.value;
                }
            }
        });
        
        localStorage.setItem(saveKey, JSON.stringify(data));
    });
    
    // Clear on successful submit
    form.addEventListener('submit', function() {
        if (form.checkValidity()) {
            localStorage.removeItem(saveKey);
        }
    });
}

// Phone number formatting
function formatPhoneNumber(input) {
    let value = input.value.replace(/\D/g, '');
    
    if (value.length > 0) {
        if (value.length <= 3) {
            value = value;
        } else if (value.length <= 6) {
            value = value.slice(0, 3) + ' ' + value.slice(3);
        } else if (value.length <= 10) {
            value = value.slice(0, 3) + ' ' + value.slice(3, 6) + ' ' + value.slice(6);
        } else {
            value = value.slice(0, 3) + ' ' + value.slice(3, 6) + ' ' + value.slice(6, 10);
        }
    }
    
    input.value = value;
}

// Export utility functions
window.registrarUtils = {
    showSuccessAnimation,
    formatCurrency,
    autoSaveForm,
    formatPhoneNumber
};

// Load message notifications script
(function() {
    if (!window.location.pathname.includes('/messages/')) {
        const script = document.createElement('script');
        script.src = '../shared/js/message-notifications.js?v=' + Date.now();
        script.async = true;
        document.head.appendChild(script);
    }
})();
