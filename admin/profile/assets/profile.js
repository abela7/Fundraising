// Profile JavaScript

// Toggle Edit Mode
function toggleEdit(section) {
    const form = document.getElementById(`${section}Form`);
    const inputs = form.querySelectorAll('input:not([readonly])');
    const actions = form.querySelector('.form-actions');
    const editBtn = form.closest('.settings-section').querySelector('.btn-outline-primary');
    
    const isEditing = !inputs[0].disabled;
    
    inputs.forEach(input => {
        input.disabled = isEditing;
    });
    
    actions.style.display = isEditing ? 'none' : 'flex';
    editBtn.innerHTML = isEditing ? '<i class="fas fa-edit"></i> Edit' : '<i class="fas fa-times"></i> Cancel';
    
    // Change button onclick
    if (isEditing) {
        editBtn.setAttribute('onclick', `toggleEdit('${section}')`);
    } else {
        editBtn.setAttribute('onclick', `cancelEdit('${section}')`);
    }
}

// Cancel Edit
function cancelEdit(section) {
    const form = document.getElementById(`${section}Form`);
    const inputs = form.querySelectorAll('input:not([readonly])');
    const actions = form.querySelector('.form-actions');
    const editBtn = form.closest('.settings-section').querySelector('.btn-outline-primary');
    
    // Reset values (in real app, restore original values)
    inputs.forEach(input => {
        input.disabled = true;
    });
    
    actions.style.display = 'none';
    editBtn.innerHTML = '<i class="fas fa-edit"></i> Edit';
    editBtn.setAttribute('onclick', `toggleEdit('${section}')`);
}

// Change Avatar (placeholder upload)
function changeAvatar() {
    // Create file input
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/*';
    
    input.onchange = function(e) {
        const file = e.target.files[0];
        if (file) {
            // In real app, upload file and update avatar
            alert('Avatar upload functionality would be implemented here');
        }
    };
    
    input.click();
}

// Note: password change handled via server POST in modal (#changePasswordModal)

// View Login History
function viewLoginHistory() {
    // In real app, this would open a modal or navigate to history page
    alert('Login history would be displayed here with details like:\n\n' +
          '• Login times and dates\n' +
          '• IP addresses\n' +
          '• Device information\n' +
          '• Geographic locations');
}

// End Session
function endSession(button) {
    if (confirm('Are you sure you want to end this session?')) {
        const sessionItem = button.closest('.session-item');
        
        // Animate removal
        sessionItem.style.transform = 'translateX(100%)';
        sessionItem.style.opacity = '0';
        
        setTimeout(() => {
            sessionItem.remove();
            showToast('Session ended successfully', 'success');
        }, 300);
    }
}

// Form Submit Handlers
// Let personal form submit to server normally

document.getElementById('preferencesForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    
    // Show loading
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';
    submitBtn.disabled = true;
    
    // Simulate save
    setTimeout(() => {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
        
        // Show success
        showToast('Preferences saved successfully!', 'success');
    }, 1000);
});

// 2FA Toggle Handler
document.getElementById('2faToggle')?.addEventListener('change', function() {
    if (this.checked) {
        // In real app, this would initiate 2FA setup
        if (confirm('Enable Two-Factor Authentication? You will receive setup instructions via WhatsApp.')) {
            showToast('2FA setup instructions sent to your email', 'info');
        } else {
            this.checked = false;
        }
    } else {
        if (confirm('Disable Two-Factor Authentication? This will reduce your account security.')) {
            showToast('Two-Factor Authentication disabled', 'warning');
        } else {
            this.checked = true;
        }
    }
});

// Show Toast Notification
function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = 'toast-notification';
    
    const icons = {
        success: 'check-circle',
        error: 'exclamation-circle',
        warning: 'exclamation-triangle',
        info: 'info-circle'
    };
    
    const colors = {
        success: 'rgba(16, 185, 129, 0.9)',
        error: 'rgba(239, 68, 68, 0.9)',
        warning: 'rgba(245, 158, 11, 0.9)',
        info: 'rgba(59, 130, 246, 0.9)'
    };
    
    toast.innerHTML = `
        <i class="fas fa-${icons[type]} me-2"></i>
        ${message}
    `;
    
    // Add styles
    toast.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 1rem 1.5rem;
        background: ${colors[type]};
        color: white;
        border-radius: 8px;
        transform: translateX(400px);
        transition: transform 0.3s ease;
        z-index: 9999;
        min-width: 300px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
    `;
    
    document.body.appendChild(toast);
    
    // Animate in
    setTimeout(() => {
        toast.style.transform = 'translateX(0)';
    }, 10);
    
    // Remove after delay
    setTimeout(() => {
        toast.style.transform = 'translateX(400px)';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// Animate stats on load
window.addEventListener('load', function() {
    const stats = document.querySelectorAll('.stat-value');
    stats.forEach((stat, index) => {
        const finalValue = stat.textContent;
        stat.textContent = '0';
        
        setTimeout(() => {
            // Animate number counting (simplified)
            let current = 0;
            const target = parseInt(finalValue.replace(/[^\d]/g, ''));
            const increment = target / 20;
            
            const timer = setInterval(() => {
                current += increment;
                if (current >= target) {
                    stat.textContent = finalValue;
                    clearInterval(timer);
                } else {
                    stat.textContent = Math.floor(current).toString();
                    if (finalValue.includes('%')) stat.textContent += '%';
                }
            }, 50);
        }, index * 200);
    });
});
