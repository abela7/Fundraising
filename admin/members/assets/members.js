// Members Page JavaScript

/** @type {DataTables.Api|null} */
let membersDataTable = null;

// Initialize tooltips and DataTable
$(document).ready(function() {
  // Initialize DataTable (hide default search - we use custom filter bar)
  membersDataTable = $('#membersTable').DataTable({
    order: [[5, 'desc']], // Default sort by Joined (col 5) descending
    pageLength: 25,
    lengthMenu: [[25, 50, 100, 250, 500, -1], [25, 50, 100, 250, 500, "All"]],
    dom: 'lrtip',
    language: {
      lengthMenu: "Show _MENU_ members per page",
      info: "Showing _START_ to _END_ of _TOTAL_ members",
      infoEmpty: "No members to show",
      infoFiltered: "(filtered from _MAX_ total)"
    },
    columnDefs: [
      { targets: 0, orderable: false, searchable: false },
      { targets: 6, orderable: false, searchable: false }
    ]
  });

  // Wire filter inputs to DataTable
  const $search = $('#filterSearch');
  const $role = $('#filterRole');
  const $status = $('#filterStatus');
  const $clear = $('#filterClear');

  function applyFilters() {
    const searchVal = ($search.val() || '').trim();
    const roleVal = ($role.val() || '').toLowerCase();
    const statusVal = ($status.val() || '').toLowerCase();

    membersDataTable.search(searchVal);

    const roleSearch = roleVal === 'admin' ? 'Admin' : roleVal === 'registrar' ? 'Registrar' : '';
    const statusSearch = statusVal === 'active' ? 'Active' : statusVal === 'inactive' ? 'Inactive' : '';

    membersDataTable.columns(3).search(roleSearch);
    membersDataTable.columns(4).search(statusSearch);
    membersDataTable.draw();
  }

  $search.on('keyup', function() {
    clearTimeout(window.membersSearchTimeout);
    window.membersSearchTimeout = setTimeout(applyFilters, 300);
  });
  $role.on('change', applyFilters);
  $status.on('change', applyFilters);

  $clear.on('click', function() {
    $search.val('');
    $role.val('');
    $status.val('');
    applyFilters();
  });

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

// Delete member permanently - now handled by inline form with PHP confirmation

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

