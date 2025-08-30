<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_admin();

$db = db();
$msg = '';
$error = '';

// Check if last_login_at column exists (resilient to schema variations)
$hasLastLogin = false;
try {
    $col = $db->query("SHOW COLUMNS FROM users LIKE 'last_login_at'");
    if ($col && $col->num_rows > 0) { $hasLastLogin = true; }
} catch (Throwable $e) {
    $hasLastLogin = false;
}

$sql = 'SELECT id, name, phone, email, role, active, created_at' .
        ($hasLastLogin ? ', last_login_at' : ', NULL as last_login_at') .
        ' FROM users ORDER BY (role = "admin") DESC, name ASC';
$rows = [];
try {
    $res = $db->query($sql);
    if ($res) { $rows = $res->fetch_all(MYSQLI_ASSOC); }
} catch (Throwable $e) {
    $error = 'Failed to load user status: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>User Login Status - Fundraising Admin</title>
  <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="../assets/admin.css">
</head>
<body>
<div class="admin-wrapper">
  <?php include '../includes/sidebar.php'; ?>
  <div class="admin-content">
    <?php include '../includes/topbar.php'; ?>

    <main class="main-content">
      <?php if ($msg): ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert">
          <i class="fas fa-info-circle me-2"></i>
          <?php echo htmlspecialchars($msg); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
          <i class="fas fa-exclamation-triangle me-2"></i>
          <?php echo htmlspecialchars($error); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>

      <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
          <h1 class="h3 mb-1">User Login Status</h1>
          <p class="text-muted mb-0">See which registrars and admins have logged in and when</p>
        </div>
      </div>

      <div class="card">
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover mb-0">
              <thead>
                <tr>
                  <th>User</th>
                  <th>Contact</th>
                  <th>Role</th>
                  <th>Status</th>
                  <th>Joined</th>
                  <th>Last Login</th>
                  <th>Login State</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $u): ?>
                <tr class="user-row" style="cursor: pointer;" onclick="showMemberStats(<?php echo (int)($u['id'] ?? 0); ?>, '<?php echo htmlspecialchars($u['name'] ?? '', ENT_QUOTES); ?>')">
                  <td>
                    <div class="d-flex align-items-center">
                      <div class="avatar-circle me-3">
                        <?php echo strtoupper(substr($u['name'] ?? 'U', 0, 1)); ?>
                      </div>
                      <div>
                        <div class="fw-semibold"><?php echo htmlspecialchars($u['name'] ?? ''); ?></div>
                        <small class="text-muted">ID: #<?php echo str_pad((string)($u['id'] ?? 0), 4, '0', STR_PAD_LEFT); ?></small>
                      </div>
                    </div>
                  </td>
                  <td>
                    <div>
                      <div><i class="fas fa-phone text-muted me-1"></i> <?php echo htmlspecialchars($u['phone'] ?? ''); ?></div>
                      <small class="text-muted"><i class="fas fa-envelope me-1"></i> <?php echo htmlspecialchars($u['email'] ?? ''); ?></small>
                    </div>
                  </td>
                  <td>
                    <span class="badge rounded-pill bg-<?php echo ($u['role'] ?? '') === 'admin' ? 'primary' : 'info'; ?>">
                      <i class="fas fa-<?php echo ($u['role'] ?? '') === 'admin' ? 'crown' : 'user'; ?> me-1"></i>
                      <?php echo ucfirst($u['role'] ?? ''); ?>
                    </span>
                  </td>
                  <td>
                    <?php if ((int)($u['active'] ?? 0) === 1): ?>
                      <span class="badge rounded-pill bg-success-subtle text-success">
                        <i class="fas fa-check-circle me-1"></i>Active
                      </span>
                    <?php else: ?>
                      <span class="badge rounded-pill bg-secondary">
                        <i class="fas fa-times-circle me-1"></i>Inactive
                      </span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <small class="text-muted"><?php echo isset($u['created_at']) ? date('M d, Y', strtotime((string)$u['created_at'])) : '—'; ?></small>
                  </td>
                  <td>
                    <?php if (!empty($u['last_login_at'])): ?>
                      <small class="text-muted"><?php echo date('M d, Y g:i A', strtotime((string)$u['last_login_at'])); ?></small>
                    <?php else: ?>
                      <span class="badge bg-warning-subtle text-warning">Never</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if (!empty($u['last_login_at'])): ?>
                      <span class="badge bg-success"><i class="fas fa-user-check me-1"></i>Logged In</span>
                    <?php else: ?>
                      <span class="badge bg-warning"><i class="fas fa-user-clock me-1"></i>Never Logged In</span>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </main>
  </div>
</div>

<!-- Member Statistics Modal -->
<div class="modal fade" id="memberStatsModal" tabindex="-1" aria-labelledby="memberStatsModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title" id="memberStatsModalLabel">
          <i class="fas fa-chart-bar me-2"></i>Member Performance Statistics
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="memberStatsContent">
        <div class="text-center py-5">
          <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
          </div>
          <p class="mt-3 text-muted">Loading member statistics...</p>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>

<script>
// Show member statistics modal (reused from Members page)
async function showMemberStats(userId, memberName) {
    const modal = new bootstrap.Modal(document.getElementById('memberStatsModal'));
    const modalTitle = document.getElementById('memberStatsModalLabel');
    const modalContent = document.getElementById('memberStatsContent');
    modalTitle.innerHTML = `<i class="fas fa-chart-bar me-2"></i>${memberName} - Performance Statistics`;
    modalContent.innerHTML = `
        <div class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-3 text-muted">Loading member statistics...</p>
        </div>
    `;
    modal.show();

    try {
        const response = await fetch(`../../api/member_stats.php?user_id=${userId}`);
        const data = await response.json();
        if (!data.success) { throw new Error(data.error || 'Failed to load statistics'); }
        modalContent.innerHTML = renderMemberStats(data);
    } catch (error) {
        console.error('Error loading member stats:', error);
        modalContent.innerHTML = `
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>Error:</strong> Failed to load member statistics. ${error.message}
            </div>
        `;
    }
}

function renderMemberStats(data) {
    const stats = data.statistics;
    const pledgeStats = data.pledge_stats;
    const paymentStats = data.payment_stats;
    const user = data.user;
    const recentActivity = data.recent_activity;

    let lastRegistrationText = 'Never';
    if (stats.last_registration_date) {
        const daysSince = stats.days_since_last_registration;
        if (daysSince === 0) { lastRegistrationText = 'Today'; }
        else if (daysSince === 1) { lastRegistrationText = '1 day ago'; }
        else { lastRegistrationText = `${daysSince} days ago`; }
    }

    return `
        <div class="row mb-4">
            <div class="col-md-8">
                <div class="d-flex align-items-center">
                    <div class="avatar-circle me-3 bg-primary text-white" style="width: 60px; height: 60px; font-size: 1.5rem;">
                        ${user.name.charAt(0).toUpperCase()}
                    </div>
                    <div>
                        <h4 class="mb-1">${user.name}</h4>
                        <p class="text-muted mb-0">
                            <i class="fas fa-phone me-1"></i>${user.phone} |
                            <i class="fas fa-envelope me-1"></i>${user.email}
                        </p>
                        <span class="badge bg-${user.role === 'admin' ? 'primary' : 'info'} mt-1">
                            <i class="fas fa-${user.role === 'admin' ? 'crown' : 'user'} me-1"></i>${user.role.charAt(0).toUpperCase() + user.role.slice(1)}
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-md-4 text-end">
                <div class="badge bg-light text-dark p-2">
                    <i class="fas fa-calendar-alt me-1"></i>
                    Joined: ${new Date(user.created_at).toLocaleDateString()}
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-center border-primary">
                    <div class="card-body">
                        <i class="fas fa-clipboard-list fa-2x text-primary mb-2"></i>
                        <h3 class="text-primary">${stats.total_registrations}</h3>
                        <p class="card-text text-muted mb-0">Total Registrations</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-success">
                    <div class="card-body">
                        <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                        <h3 class="text-success">${stats.total_approved}</h3>
                        <p class="card-text text-muted mb-0">Approved</p>
                        <small class="text-success">${stats.approval_rate}% approval rate</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-danger">
                    <div class="card-body">
                        <i class="fas fa-times-circle fa-2x text-danger mb-2"></i>
                        <h3 class="text-danger">${stats.total_rejected}</h3>
                        <p class="card-text text-muted mb-0">Rejected</p>
                        <small class="text-danger">${stats.rejection_rate}% rejection rate</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-warning">
                    <div class="card-body">
                        <i class="fas fa-clock fa-2x text-warning mb-2"></i>
                        <h3 class="text-warning">${stats.total_pending}</h3>
                        <p class="card-text text-muted mb-0">Pending</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header bg-info text-white">
                        <h6 class="mb-0"><i class="fas fa-hand-holding-heart me-2"></i>Pledge Statistics</h6>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-3"><strong class="text-primary">${pledgeStats.total}</strong><small class="d-block text-muted">Total</small></div>
                            <div class="col-3"><strong class="text-success">${pledgeStats.approved}</strong><small class="d-block text-muted">Approved</small></div>
                            <div class="col-3"><strong class="text-danger">${pledgeStats.rejected}</strong><small class="d-block text-muted">Rejected</small></div>
                            <div class="col-3"><strong class="text-warning">${pledgeStats.pending}</strong><small class="d-block text-muted">Pending</small></div>
                        </div>
                        <hr>
                        <div class="text-center">
                            <strong class="text-success">£${new Intl.NumberFormat().format(pledgeStats.approved_amount)}</strong>
                            <small class="d-block text-muted">Total Approved Amount</small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header bg-success text-white">
                        <h6 class="mb-0"><i class="fas fa-money-bill-wave me-2"></i>Payment Statistics</h6>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-3"><strong class="text-primary">${paymentStats.total}</strong><small class="d-block text-muted">Total</small></div>
                            <div class="col-3"><strong class="text-success">${paymentStats.approved}</strong><small class="d-block text-muted">Approved</small></div>
                            <div class="col-3"><strong class="text-danger">${paymentStats.rejected}</strong><small class="d-block text-muted">Rejected</small></div>
                            <div class="col-3"><strong class="text-warning">${paymentStats.pending}</strong><small class="d-block text-muted">Pending</small></div>
                        </div>
                        <hr>
                        <div class="text-center">
                            <strong class="text-success">£${new Intl.NumberFormat().format(paymentStats.approved_amount)}</strong>
                            <small class="d-block text-muted">Total Approved Amount</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        ${recentActivity.length > 0 ? `
        <div class="card">
            <div class="card-header">
                <h6 class="mb-0"><i class="fas fa-history me-2"></i>Recent Activity (Last 10)</h6>
            </div>
            <div class="card-body">
                <div class="list-group list-group-flush">
                    ${recentActivity.map(activity => `
                        <div class="list-group-item border-0 px-0">
                            <div class="row align-items-center">
                                <div class="col-auto">
                                    <span class="badge bg-${activity.type === 'pledge' ? 'info' : 'success'}">
                                        <i class="fas fa-${activity.type === 'pledge' ? 'hand-holding-heart' : 'money-bill-wave'} me-1"></i>
                                        ${activity.type.charAt(0).toUpperCase() + activity.type.slice(1)}
                                    </span>
                                </div>
                                <div class="col">
                                    <strong>${activity.donor_name || 'Anonymous'}</strong>
                                    <small class="text-muted d-block">${new Date(activity.created_at).toLocaleString()}</small>
                                </div>
                                <div class="col-auto">
                                    <strong class="text-primary">£${new Intl.NumberFormat().format(activity.amount)}</strong>
                                </div>
                                <div class="col-auto">
                                    <span class="badge bg-${activity.status === 'approved' ? 'success' : (activity.status === 'rejected' ? 'danger' : 'warning')}">
                                        ${activity.status.charAt(0).toUpperCase() + activity.status.slice(1)}
                                    </span>
                                </div>
                            </div>
                        </div>
                    `).join('')}
                </div>
            </div>
        </div>
        ` : `
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>
            No recent activity found for this member.
        </div>`}
    `;
}
</script>
</body>
</html>


