// Pledges Page JavaScript

// Initialize tooltips
document.addEventListener('DOMContentLoaded', function() {
  const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
  tooltipTriggerList.map(function (tooltipTriggerEl) {
    return new bootstrap.Tooltip(tooltipTriggerEl);
  });
});

// View pledge details
function viewPledgeDetails(pledge) {
  const modalBody = document.getElementById('pledgeDetailsBody');
  
  const showAnonymous = (pledge.type === 'paid' && Number(pledge.anonymous) === 1);
  const details = `
    <div class="pledge-details">
      <div class="pledge-detail-row">
        <span class="pledge-detail-label">Pledge ID:</span>
        <span class="pledge-detail-value">#${String(pledge.id).padStart(5, '0')}</span>
      </div>
      <div class="pledge-detail-row">
        <span class="pledge-detail-label">Donor:</span>
        <span class="pledge-detail-value">
          ${showAnonymous ? '<i class="fas fa-user-secret me-1"></i>Anonymous' : (pledge.full_name || '')}
        </span>
      </div>
      ${!showAnonymous ? `
      <div class="pledge-detail-row">
        <span class="pledge-detail-label">Phone:</span>
        <span class="pledge-detail-value">${pledge.phone || ''}</span>
      </div>
      ` : ''}
      <div class="pledge-detail-row">
        <span class="pledge-detail-label">Amount:</span>
        <span class="pledge-detail-value fw-bold">£${parseFloat(pledge.amount).toFixed(2)}</span>
      </div>
      <div class="pledge-detail-row">
        <span class="pledge-detail-label">Square Meters:</span>
        <span class="pledge-detail-value">${parseFloat(pledge.sqm_meters).toFixed(2)} m²</span>
      </div>
      <div class="pledge-detail-row">
        <span class="pledge-detail-label">Type:</span>
        <span class="pledge-detail-value">
          ${pledge.type === 'paid' 
            ? '<span class="badge bg-success"><i class="fas fa-check me-1"></i>Paid</span>' 
            : '<span class="badge bg-warning"><i class="fas fa-clock me-1"></i>Pledge</span>'}
        </span>
      </div>
      <div class="pledge-detail-row">
        <span class="pledge-detail-label">Status:</span>
        <span class="pledge-detail-value">
          ${getStatusBadge(pledge.status)}
        </span>
      </div>
      <div class="pledge-detail-row">
        <span class="pledge-detail-label">Created:</span>
        <span class="pledge-detail-value">${formatDate(pledge.created_at)}</span>
      </div>
      ${pledge.approved_by_name ? `
      <div class="pledge-detail-row">
        <span class="pledge-detail-label">Approved By:</span>
        <span class="pledge-detail-value">${pledge.approved_by_name}</span>
      </div>
      ` : ''}
      ${pledge.notes ? `
      <div class="pledge-detail-row">
        <span class="pledge-detail-label">Notes:</span>
        <span class="pledge-detail-value">${pledge.notes}</span>
      </div>
      ` : ''}
    </div>
  `;
  
  modalBody.innerHTML = details;
  
  const modal = new bootstrap.Modal(document.getElementById('pledgeDetailsModal'));
  modal.show();
}

// Get status badge HTML
function getStatusBadge(status) {
  switch(status) {
    case 'approved':
      return '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Approved</span>';
    case 'pending':
      return '<span class="badge bg-warning"><i class="fas fa-clock me-1"></i>Pending</span>';
    case 'rejected':
      return '<span class="badge bg-danger"><i class="fas fa-times-circle me-1"></i>Rejected</span>';
    default:
      return status;
  }
}

// Format date
function formatDate(dateString) {
  const date = new Date(dateString);
  return date.toLocaleDateString('en-GB', { 
    year: 'numeric', 
    month: 'long', 
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  });
}

// Approve pledge (routes to Approvals actions)
function approvePledge(id) {
  if (!confirm('Approve this pledge?')) return;
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = '../approvals/index.php';
  form.innerHTML = `
    <input type="hidden" name="pledge_id" value="${id}">
    <input type="hidden" name="do" value="approve">
  `;
  document.body.appendChild(form);
  form.submit();
}

function rejectPledge(id) {
  if (!confirm('Reject this pledge?')) return;
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = '../approvals/index.php';
  form.innerHTML = `
    <input type="hidden" name="pledge_id" value="${id}">
    <input type="hidden" name="do" value="reject">
  `;
  document.body.appendChild(form);
  form.submit();
}

// Export pledges
function exportPledges() {
  // Get current filter parameters
  const params = new URLSearchParams(window.location.search);
  params.append('export', 'csv');
  
  // Download CSV with current filters
  window.location.href = `?${params.toString()}`;
}

// Add keyboard shortcuts
document.addEventListener('keydown', function(e) {
  // Ctrl/Cmd + F: Focus search
  if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
    e.preventDefault();
    const searchInput = document.querySelector('input[name="search"]');
    if (searchInput) {
      searchInput.focus();
      searchInput.select();
    }
  }
  
  // Ctrl/Cmd + E: Export
  if ((e.ctrlKey || e.metaKey) && e.key === 'e') {
    e.preventDefault();
    exportPledges();
  }
});

// Add loading state to filter form
const filterForm = document.querySelector('form');
if (filterForm) {
  filterForm.addEventListener('submit', function() {
    const submitBtn = this.querySelector('button[type="submit"]');
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Filtering...';
    }
  });
}
