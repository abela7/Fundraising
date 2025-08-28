// Members Page JavaScript

// Initialize tooltips
document.addEventListener('DOMContentLoaded', function() {
  // Bootstrap tooltips
  const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
  tooltipTriggerList.map(function (tooltipTriggerEl) {
    return new bootstrap.Tooltip(tooltipTriggerEl);
  });

  // Form validation
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
});

// Edit member function
function editMember(member) {
  // Populate edit form
  document.getElementById('edit_id').value = member.id;
  document.getElementById('edit_name').value = member.name;
  document.getElementById('edit_phone').value = member.phone;
  document.getElementById('edit_email').value = member.email;
  document.getElementById('edit_role').value = member.role;
  document.getElementById('edit_active').value = member.active;
  
  // Show modal
  const modal = new bootstrap.Modal(document.getElementById('editMemberModal'));
  modal.show();
}

// Reset member code
function resetMemberCode(id, name) {
  if (confirm(`Reset login code for ${name}? The new code will be displayed after reset.`)) {
    document.getElementById('reset_id').value = id;
    document.getElementById('resetCodeForm').submit();
  }
}

// Toggle member status
function toggleMemberStatus(id, action) {
  const message = action === 'delete' 
    ? 'Are you sure you want to deactivate this member?' 
    : 'Are you sure you want to activate this member?';
  
  if (confirm(message)) {
    document.getElementById('toggle_action').value = action;
    document.getElementById('toggle_id').value = id;
    document.getElementById('toggleStatusForm').submit();
  }
}

// Delete member permanently
function deleteMemberPermanently(id, name) {
  // Set member details in modal
  document.getElementById('deleteMemberName').textContent = name;
  document.getElementById('delete_id').value = id;
  
  // Show confirmation modal
  const modal = new bootstrap.Modal(document.getElementById('deleteMemberModal'));
  modal.show();
  
  // Handle confirm button click
  const confirmBtn = document.getElementById('confirmDeleteBtn');
  confirmBtn.onclick = function() {
    // Add loading state
    confirmBtn.disabled = true;
    confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Deleting...';
    
    // Submit the form
    document.getElementById('deleteMemberForm').submit();
  };
}

// Add loading state to forms
document.querySelectorAll('form').forEach(form => {
  form.addEventListener('submit', function() {
    const submitBtn = this.querySelector('button[type="submit"]');
    if (submitBtn) {
      submitBtn.disabled = true;
      const originalText = submitBtn.innerHTML;
      submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Processing...';
      
      // Re-enable after 3 seconds (in case of error)
      setTimeout(() => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
      }, 3000);
    }
  });
});

// Search functionality (optional enhancement)
function addSearchFunctionality() {
  const searchInput = document.createElement('input');
  searchInput.type = 'text';
  searchInput.className = 'form-control form-control-sm';
  searchInput.placeholder = 'Search members...';
  searchInput.style.maxWidth = '300px';
  
  // Add search to header if space available
  const headerRight = document.querySelector('.main-content > div:first-child');
  if (headerRight) {
    const searchWrapper = document.createElement('div');
    searchWrapper.className = 'd-none d-md-block';
    searchWrapper.appendChild(searchInput);
    headerRight.insertBefore(searchWrapper, headerRight.lastElementChild);
    
    // Implement search
    searchInput.addEventListener('keyup', function() {
      const searchTerm = this.value.toLowerCase();
      const rows = document.querySelectorAll('tbody tr');
      
      rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(searchTerm) ? '' : 'none';
      });
    });
  }
}

// Add search on page load
document.addEventListener('DOMContentLoaded', addSearchFunctionality);
