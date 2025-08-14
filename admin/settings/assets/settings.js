/**
 * Modern Settings Page JavaScript
 * Handles all dynamic functionality for the settings page
 */

document.addEventListener('DOMContentLoaded', function() {
    console.log('Settings page DOM fully loaded and parsed.');

    // Attach event listeners
    const addPackageBtn = document.getElementById('addPackageBtn');
    if (addPackageBtn) {
        addPackageBtn.addEventListener('click', addNewPackage);
    }

    const savePackageBtn = document.getElementById('savePackageBtn');
    if (savePackageBtn) {
        savePackageBtn.addEventListener('click', savePackage);
    }
    
    const refreshBtn = document.getElementById('refreshDataBtn');
    if(refreshBtn) {
        refreshBtn.addEventListener('click', refreshData);
    }

    // Use event delegation for dynamically added buttons
    const packagesContainer = document.getElementById('packagesContainer');
    if (packagesContainer) {
        packagesContainer.addEventListener('click', function(e) {
            const editBtn = e.target.closest('.btn-edit-package');
            if (editBtn) {
                console.log('Edit button clicked');
                const packageId = editBtn.dataset.packageId;
                const label = editBtn.dataset.label;
                const price = editBtn.dataset.price;
                const sqm = editBtn.dataset.sqm;
                editPackage(packageId, label, price, sqm);
                return;
            }

            const deleteBtn = e.target.closest('.btn-delete-package');
            if (deleteBtn) {
                console.log('Delete button clicked');
                const packageId = deleteBtn.dataset.packageId;
                deletePackage(packageId);
                return;
            }
        });
    }
    
    initializeTooltips();
    console.log('Event listeners attached.');
});

// Refresh data function
function refreshData() {
    console.log('Refreshing page data...');
    const btn = document.getElementById('refreshDataBtn');
    if(btn) {
        setLoading(btn, 'Refreshing...');
    }
    location.reload();
}

// Edit package function
function editPackage(packageId, label, price, sqm) {
    console.log(`Editing package: ${packageId}, ${label}, ${price}, ${sqm}`);
    document.getElementById('packageId').value = packageId;
    document.getElementById('packageLabel').value = label || '';
    document.getElementById('packagePrice').value = parseFloat(price) || '';
    document.getElementById('packageSqm').value = parseFloat(sqm) || '';
    document.getElementById('modalTitle').textContent = 'Edit Package';
    
    const modal = new bootstrap.Modal(document.getElementById('packageModal'));
    modal.show();
}

// Add new package function
function addNewPackage() {
    console.log('Adding new package.');
    document.getElementById('packageForm').reset();
    document.getElementById('packageId').value = '0'; // Use 0 for a new package
    document.getElementById('modalTitle').textContent = 'Add New Package';
    
    const modal = new bootstrap.Modal(document.getElementById('packageModal'));
    modal.show();
}

// Save package (Add or Update)
async function savePackage() {
    const form = document.getElementById('packageForm');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    
    const packageId = document.getElementById('packageId').value;
    const isNew = packageId === '0';
    const action = isNew ? 'add_package' : 'update_package';
    console.log(`Saving package. Action: ${action}, ID: ${packageId}`);

    const formData = new FormData(form);
    formData.append('action', action);
    formData.append('package_id', packageId);
    formData.append('csrf_token', document.querySelector('[name="csrf_token"]').value);

    const saveBtn = document.getElementById('savePackageBtn');
    const originalText = saveBtn.innerHTML;
    setLoading(saveBtn, 'Saving...');

    try {
        const response = await fetch('', {
            method: 'POST',
            body: new URLSearchParams(formData)
        });
        const result = await response.json();
        console.log('Save response:', result);

        if (result.status === 'success') {
            showSuccess(result.message);
            
            if (isNew) {
                const newPackageData = {
                    id: result.new_id,
                    label: formData.get('label'),
                    price: formData.get('price'),
                    sqm_meters: formData.get('sqm_meters')
                };
                addPackageToList(newPackageData);
            } else {
                updatePackageInList(packageId, {
                    label: formData.get('label'),
                    price: formData.get('price'),
                    sqm_meters: formData.get('sqm_meters')
                });
            }
            const modal = bootstrap.Modal.getInstance(document.getElementById('packageModal'));
            modal.hide();
        } else {
            showError(result.message || 'An unknown error occurred.');
        }
    } catch (error) {
        console.error('Error saving package:', error);
        showError('A network or server error occurred.');
    } finally {
        resetLoading(saveBtn, originalText);
    }
}

// Delete package function
async function deletePackage(packageId) {
    if (!confirm('Are you sure you want to delete this package? This cannot be undone.')) {
        return;
    }
    console.log(`Deleting package: ${packageId}`);

    const deleteBtn = document.querySelector(`.btn-delete-package[data-package-id="${packageId}"]`);
    const originalContent = deleteBtn.innerHTML;
    setLoading(deleteBtn, '');

    try {
        const formData = new URLSearchParams();
        formData.append('action', 'delete_package');
        formData.append('package_id', packageId);
        formData.append('csrf_token', document.querySelector('[name="csrf_token"]').value);

        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        console.log('Delete response:', result);

        if (result.status === 'success') {
            showSuccess(result.message);
            const itemToRemove = document.querySelector(`.package-item[data-package-id="${packageId}"]`);
            if (itemToRemove) itemToRemove.remove();
        } else {
            showError(result.message || 'Failed to delete.');
        }
    } catch (error) {
        console.error('Error deleting package:', error);
        showError('A network or server error occurred.');
    } finally {
        resetLoading(deleteBtn, originalContent);
    }
}

// --- UI Helper Functions ---

function addPackageToList(pkg) {
    console.log('Adding package to list:', pkg);
    const container = document.getElementById('packagesContainer');
    const currencyCode = document.querySelector('.input-group-text').textContent;
    const newPackageHTML = `
        <div class="package-item d-flex justify-content-between align-items-center p-3 mb-2 border rounded" data-package-id="${pkg.id}">
          <div class="package-info">
            <h6 class="mb-1">${escapeHTML(pkg.label)}</h6>
            <div class="package-details">
              <span class="badge bg-success me-2">${currencyCode} ${parseFloat(pkg.price).toFixed(2)}</span>
              ${pkg.sqm_meters > 0 ? `<span class="badge bg-info">${parseFloat(pkg.sqm_meters).toFixed(2)} m²</span>` : ''}
            </div>
          </div>
          <div class="package-actions d-flex gap-2">
            <button type="button" class="btn btn-sm btn-outline-primary btn-edit-package" 
                    data-package-id="${pkg.id}" 
                    data-label="${escapeHTML(pkg.label)}" 
                    data-price="${pkg.price}" 
                    data-sqm="${pkg.sqm_meters}">
              <i class="fas fa-edit"></i>
            </button>
            <button type="button" class="btn btn-sm btn-outline-danger btn-delete-package" data-package-id="${pkg.id}">
              <i class="fas fa-trash"></i>
            </button>
          </div>
        </div>`;
    container.insertAdjacentHTML('beforeend', newPackageHTML);
    const emptyState = container.querySelector('.text-center');
    if (emptyState) emptyState.remove();
}

function updatePackageInList(packageId, pkg) {
    console.log(`Updating package in list: ${packageId}`, pkg);
    const item = document.querySelector(`.package-item[data-package-id="${packageId}"]`);
    if (!item) return;

    const currencyCode = document.querySelector('.input-group-text').textContent;
    item.querySelector('h6').textContent = pkg.label;
    item.querySelector('.badge.bg-success').textContent = `${currencyCode} ${parseFloat(pkg.price).toFixed(2)}`;
    
    let sqmBadge = item.querySelector('.badge.bg-info');
    if (pkg.sqm_meters > 0) {
        if (!sqmBadge) {
            sqmBadge = document.createElement('span');
            sqmBadge.className = 'badge bg-info';
            item.querySelector('.package-details').appendChild(sqmBadge);
        }
        sqmBadge.textContent = `${parseFloat(pkg.sqm_meters).toFixed(2)} m²`;
    } else if (sqmBadge) {
        sqmBadge.remove();
    }
    
    const editBtn = item.querySelector('.btn-edit-package');
    editBtn.dataset.label = pkg.label;
    editBtn.dataset.price = pkg.price;
    editBtn.dataset.sqm = pkg.sqm_meters;
}

function escapeHTML(str) {
    const p = document.createElement("p");
    p.textContent = str;
    return p.innerHTML;
}

// Tooltips
function initializeTooltips() {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
}

// Utility functions for notifications
function showSuccess(message) {
    console.log(`Success: ${message}`);
    showNotification(message, 'success');
}

function showError(message) {
    console.error(`Error: ${message}`);
    showNotification(message, 'danger');
}

function showNotification(message, type = 'info') {
    const container = document.getElementById('notification-container');
    if (!container) return;

    const notification = document.createElement('div');
    notification.className = `alert alert-${type} alert-dismissible fade show`;
    notification.role = 'alert';
    notification.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    `;
    container.appendChild(notification);

    setTimeout(() => {
        const alert = bootstrap.Alert.getOrCreateInstance(notification);
        if (alert) alert.close();
    }, 5000);
}

// Loading state helpers
function setLoading(button, text) {
    button.disabled = true;
    button.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> ${text}`;
}

function resetLoading(button, originalText) {
    button.disabled = false;
    button.innerHTML = originalText;
}

// Form submission enhancement
document.addEventListener('submit', function(e) {
    const form = e.target;
    const submitBtn = form.querySelector('button[type="submit"]');
    
    if (submitBtn && !submitBtn.disabled) {
        const originalText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.classList.add('loading');
        
        // Re-enable after 5 seconds as fallback
        setTimeout(() => {
            submitBtn.disabled = false;
            submitBtn.classList.remove('loading');
        }, 5000);
    }
});

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Ctrl+S to save settings
    if (e.ctrlKey && e.key === 's') {
        e.preventDefault();
        const mainForm = document.getElementById('mainSettingsForm');
        if (mainForm) {
            mainForm.requestSubmit();
        }
    }
    
    // Escape to close modals
    if (e.key === 'Escape') {
        const openModal = document.querySelector('.modal.show');
        if (openModal) {
            const modal = bootstrap.Modal.getInstance(openModal);
            if (modal) {
                modal.hide();
            }
        }
    }
});

// Handle page unload
window.addEventListener('beforeunload', function(e) {
    const forms = document.querySelectorAll('form');
    let hasUnsavedChanges = false;
    
    forms.forEach(form => {
        const formData = new FormData(form);
        const originalData = form.dataset.originalData;
        
        if (originalData) {
            const current = JSON.stringify(Object.fromEntries(formData.entries()));
            if (current !== originalData) {
                hasUnsavedChanges = true;
            }
        }
    });
    
    if (hasUnsavedChanges) {
        e.preventDefault();
        e.returnValue = '';
    }
});

// Store original form data on page load
window.addEventListener('load', function() {
    document.querySelectorAll('form').forEach(form => {
        const formData = new FormData(form);
        form.dataset.originalData = JSON.stringify(Object.fromEntries(formData.entries()));
    });
});

// Error handling for AJAX requests
window.addEventListener('unhandledrejection', function(e) {
    console.error('Unhandled promise rejection:', e.reason);
    showError('An unexpected error occurred. Please refresh the page and try again.');
});

// Network status handling
window.addEventListener('online', function() {
    showSuccess('Connection restored');
});

window.addEventListener('offline', function() {
    showError('Connection lost. Changes may not be saved.');
});

// Console logging for debugging
if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') {
    console.log('Settings page debug mode enabled');
    
    // Expose useful functions to console
    window.settingsDebug = {
        showSuccess,
        showError,
        showInfo,
        exportSettings,
        copyToClipboard,
        exportData
    };
}