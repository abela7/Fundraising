<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../shared/audit_helper.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/url.php';
require_admin();

$page_title = 'Members Management';
$db = db();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'create' || $action === 'update') {
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? 'registrar';
        $active = (int)($_POST['active'] ?? 1);
        if ($action === 'create') {
            $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $hash = password_hash($code, PASSWORD_DEFAULT);
            $stmt = $db->prepare('INSERT INTO users (name, phone, email, role, password_hash, active, created_at) VALUES (?,?,?,?,?,?,NOW())');
            $stmt->bind_param('sssssi', $name, $phone, $email, $role, $hash, $active);
            $stmt->execute();
            $new_user_id = $db->insert_id;
            
            // Audit log
            log_audit(
                $db,
                'create',
                'user',
                $new_user_id,
                null,
                ['name' => $name, 'phone' => $phone, 'email' => $email, 'role' => $role, 'active' => $active],
                'admin_portal',
                (int)($_SESSION['user']['id'] ?? 0)
            );
            
            $msg = 'Member created. Code: ' . $code;
        } else {
            $id = (int)$_POST['id'];
            
            // Get before data
            $before_stmt = $db->prepare('SELECT name, phone, email, role, active FROM users WHERE id = ?');
            $before_stmt->bind_param('i', $id);
            $before_stmt->execute();
            $beforeData = $before_stmt->get_result()->fetch_assoc();
            $before_stmt->close();
            
            $stmt = $db->prepare('UPDATE users SET name=?, phone=?, email=?, role=?, active=? WHERE id=?');
            $stmt->bind_param('ssssii', $name, $phone, $email, $role, $active, $id);
            $stmt->execute();
            
            // Audit log
            log_audit(
                $db,
                'update',
                'user',
                $id,
                $beforeData,
                ['name' => $name, 'phone' => $phone, 'email' => $email, 'role' => $role, 'active' => $active],
                'admin_portal',
                (int)($_SESSION['user']['id'] ?? 0)
            );
            
            $msg = 'Member updated';
        }
    } elseif ($action === 'reset_code') {
        $id = (int)$_POST['id'];
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $hash = password_hash($code, PASSWORD_DEFAULT);
        $stmt = $db->prepare('UPDATE users SET password_hash=? WHERE id=?');
        $stmt->bind_param('si', $hash, $id);
        $stmt->execute();
        
        // Audit log
        log_audit(
            $db,
            'reset_password',
            'user',
            $id,
            null,
            ['password_reset' => true],
            'admin_portal',
            (int)($_SESSION['user']['id'] ?? 0)
        );
        
        $msg = 'Code reset to: ' . $code;
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        
        // Get before data
        $before_stmt = $db->prepare('SELECT name, active FROM users WHERE id = ?');
        $before_stmt->bind_param('i', $id);
        $before_stmt->execute();
        $beforeData = $before_stmt->get_result()->fetch_assoc();
        $before_stmt->close();
        
        $db->query('UPDATE users SET active=0 WHERE id=' . $id);
        
        // Audit log
        log_audit(
            $db,
            'update',
            'user',
            $id,
            $beforeData ? ['active' => $beforeData['active']] : null,
            ['active' => 0],
            'admin_portal',
            (int)($_SESSION['user']['id'] ?? 0)
        );
        
        $msg = 'Member deactivated';
    } elseif ($action === 'activate') {
        $id = (int)$_POST['id'];
        
        // Get before data
        $before_stmt = $db->prepare('SELECT name, active FROM users WHERE id = ?');
        $before_stmt->bind_param('i', $id);
        $before_stmt->execute();
        $beforeData = $before_stmt->get_result()->fetch_assoc();
        $before_stmt->close();
        
        $db->query('UPDATE users SET active=1 WHERE id=' . $id);
        
        // Audit log
        log_audit(
            $db,
            'update',
            'user',
            $id,
            $beforeData ? ['active' => $beforeData['active']] : null,
            ['active' => 1],
            'admin_portal',
            (int)($_SESSION['user']['id'] ?? 0)
        );
        
        $msg = 'Member activated';
    } elseif ($action === 'permanent_delete') {
        $id = (int)$_POST['id'];
        // First check if this member has any associated data
        $stmt = $db->prepare('SELECT COUNT(*) as count FROM pledges WHERE approved_by_user_id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        if ($result['count'] > 0) {
            $msg = 'Cannot delete member: Member has associated pledge approvals. Please deactivate instead.';
        } else {
            // Check for payments as well
            $stmt = $db->prepare('SELECT COUNT(*) as count FROM payments WHERE received_by_user_id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            
            if ($result['count'] > 0) {
                $msg = 'Cannot delete member: Member has associated payment records. Please deactivate instead.';
            } else {
                // Safe to delete - use transaction to ensure both operations succeed
                $db->begin_transaction();
                
                try {
                    // First, delete any related registrar application record
                    // Find the registrar application by matching email and phone
                    $stmt = $db->prepare('SELECT email, phone FROM users WHERE id = ?');
                    $stmt->bind_param('i', $id);
                    $stmt->execute();
                    $userResult = $stmt->get_result()->fetch_assoc();
                    
                    if ($userResult) {
                        // Delete matching registrar application
                        $stmt = $db->prepare('DELETE FROM registrar_applications WHERE email = ? OR phone = ?');
                        $stmt->bind_param('ss', $userResult['email'], $userResult['phone']);
                        $stmt->execute();
                    }
                    
                    // Then delete the user
                    $stmt = $db->prepare('DELETE FROM users WHERE id = ?');
                    $stmt->bind_param('i', $id);
                    $stmt->execute();
                    
                    // Commit the transaction
                    $db->commit();
                    $msg = 'Member permanently deleted (including registrar application record)';
                    
                } catch (Exception $e) {
                    // Rollback on error
                    $db->rollback();
                    $msg = 'Error deleting member: ' . $e->getMessage();
                }
            }
        }
    }
}

$rows = $db->query("SELECT id, name, phone, email, role, active, created_at FROM users ORDER BY created_at DESC")
           ->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Members Management - Fundraising Admin</title>
  <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
  <link rel="stylesheet" href="../assets/admin.css">
  <link rel="stylesheet" href="assets/members.css">
</head>
<body>
<div class="admin-wrapper">
  <?php include '../includes/sidebar.php'; ?>
  
  <div class="admin-content">
    <?php include '../includes/topbar.php'; ?>
    
    <main class="main-content">
      <?php if ($msg): ?>
        <div class="alert alert-info alert-dismissible fade show animate-fade-in" role="alert">
          <i class="fas fa-info-circle me-2"></i>
          <?php echo htmlspecialchars($msg); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>

      <!-- Members Header -->
      <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
          <h1 class="h3 mb-1">Members Management</h1>
          <p class="text-muted mb-0">Manage admin and registrar users</p>
        </div>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addMemberModal">
          <i class="fas fa-user-plus me-2"></i>Add New Member
        </button>
      </div>

      <!-- Members Table -->
      <div class="card animate-fade-in">
        <div class="card-body p-0">
          <div class="table-responsive">
            <table id="membersTable" class="table table-hover mb-0">
              <thead>
                <tr>
                  <th>User</th>
                  <th>Contact</th>
                  <th>Role</th>
                  <th>Status</th>
                  <th>Joined</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $r): ?>
                <tr class="member-row" style="cursor: pointer;" onclick="window.location.href='view.php?id=<?php echo (int)$r['id']; ?>'" 
                    data-bs-toggle="tooltip" title="Click to view member details">
                  <td>
                    <div class="d-flex align-items-center">
                      <div class="avatar-circle me-3">
                        <?php echo strtoupper(substr($r['name'], 0, 1)); ?>
                      </div>
                      <div>
                        <div class="fw-semibold"><?php echo htmlspecialchars($r['name']); ?></div>
                        <small class="text-muted">ID: #<?php echo str_pad((string)$r['id'], 4, '0', STR_PAD_LEFT); ?></small>
                      </div>
                    </div>
                  </td>
                  <td>
                    <div>
                      <div><i class="fas fa-phone text-muted me-1"></i> <?php echo htmlspecialchars($r['phone']); ?></div>
                      <small class="text-muted"><i class="fas fa-envelope me-1"></i> <?php echo htmlspecialchars($r['email']); ?></small>
                    </div>
                  </td>
                  <td>
                    <span class="badge rounded-pill bg-<?php echo $r['role']==='admin'?'primary':'info'; ?>">
                      <i class="fas fa-<?php echo $r['role']==='admin'?'crown':'user'; ?> me-1"></i>
                      <?php echo ucfirst($r['role']); ?>
                    </span>
                  </td>
                  <td>
                    <?php if ((int)$r['active'] === 1): ?>
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
                    <small class="text-muted"><?php echo date('M d, Y', strtotime($r['created_at'])); ?></small>
                  </td>
                  <td class="text-end">
                    <div class="btn-group" role="group">
                      <button type="button" class="btn btn-sm btn-light" 
                              onclick="event.stopPropagation(); editMember(<?php echo htmlspecialchars(json_encode($r)); ?>)"
                              data-bs-toggle="tooltip" title="Edit">
                        <i class="fas fa-edit"></i>
                      </button>
                      <button type="button" class="btn btn-sm btn-light" 
                              onclick="event.stopPropagation(); resetMemberCode(<?php echo (int)$r['id']; ?>, '<?php echo htmlspecialchars($r['name']); ?>')"
                              data-bs-toggle="tooltip" title="Reset Code">
                        <i class="fas fa-key"></i>
                      </button>
                      <?php if ($r['role'] === 'registrar'): ?>
                      <button type="button" class="btn btn-sm btn-light" 
                              onclick="event.stopPropagation(); openShareWhatsAppModal(<?php echo (int)$r['id']; ?>, '<?php echo htmlspecialchars($r['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($r['phone'], ENT_QUOTES); ?>')"
                              data-bs-toggle="tooltip" title="Share via WhatsApp">
                        <i class="fab fa-whatsapp text-success"></i>
                      </button>
                      <?php endif; ?>
                      <?php if ((int)$r['active'] === 1): ?>
                      <button type="button" class="btn btn-sm btn-light text-danger" 
                              onclick="event.stopPropagation(); toggleMemberStatus(<?php echo (int)$r['id']; ?>, 'delete')"
                              data-bs-toggle="tooltip" title="Deactivate">
                        <i class="fas fa-ban"></i>
                      </button>
                      <?php else: ?>
                      <button type="button" class="btn btn-sm btn-light text-success" 
                              onclick="event.stopPropagation(); toggleMemberStatus(<?php echo (int)$r['id']; ?>, 'activate')"
                              data-bs-toggle="tooltip" title="Activate">
                        <i class="fas fa-check"></i>
                      </button>
                      <?php endif; ?>
                      <form method="post" style="display: inline;" onsubmit="return confirm('⚠️ PERMANENT DELETE WARNING ⚠️\n\nAre you sure you want to permanently delete:\n<?php echo htmlspecialchars($r['name']); ?>?\n\nThis action CANNOT be undone!\n\nClick OK to confirm deletion.');">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="action" value="permanent_delete">
                        <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-light text-danger" 
                                onclick="event.stopPropagation();"
                                data-bs-toggle="tooltip" title="Delete Permanently">
                          <i class="fas fa-trash"></i>
                        </button>
                      </form>
                    </div>
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

<!-- Add Member Modal -->
<div class="modal fade" id="addMemberModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">
          <i class="fas fa-user-plus text-primary me-2"></i>Add New Member
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" class="needs-validation" novalidate>
        <?php echo csrf_input(); ?>
        <input type="hidden" name="action" value="create">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Full Name <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control" placeholder="Enter full name" required>
            <div class="invalid-feedback">Please provide a name.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Phone Number <span class="text-danger">*</span></label>
            <input type="tel" name="phone" class="form-control" placeholder="Enter phone number" required>
            <div class="invalid-feedback">Please provide a phone number.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Email Address <span class="text-danger">*</span></label>
            <input type="email" name="email" class="form-control" placeholder="Enter email address" required>
            <div class="invalid-feedback">Please provide a valid email.</div>
          </div>
          <div class="row">
            <div class="col-md-6">
              <label class="form-label">Role</label>
              <select name="role" class="form-select">
                <option value="registrar">Registrar</option>
                <option value="admin">Admin</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Status</label>
              <select name="active" class="form-select">
                <option value="1">Active</option>
                <option value="0">Inactive</option>
              </select>
            </div>
          </div>
          <div class="alert alert-info mt-3 mb-0">
            <i class="fas fa-info-circle me-2"></i>
            A 6-digit login code will be auto-generated and displayed after creation.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-plus-circle me-2"></i>Add Member
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Share WhatsApp Modal -->
<div class="modal fade" id="shareWhatsAppModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title"><i class="fab fa-whatsapp me-2"></i>Share Registrar Instructions</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-info d-flex align-items-start">
          <i class="fab fa-whatsapp fa-lg me-3 text-success"></i>
          <div>
            <div class="fw-semibold">Message preview will include a fresh login code.</div>
            <small class="text-muted">A new 6-digit code will be generated securely.</small>
          </div>
        </div>
        <pre id="whatsappMessagePreview" class="p-3 bg-light border rounded" style="white-space: pre-wrap;"></pre>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" onclick="copyRegistrarShareMessage()">
          <i class="fas fa-copy me-2"></i>Copy Message
        </button>
        <button type="button" class="btn btn-success" onclick="shareRegistrarOnWhatsApp()">
          <i class="fab fa-whatsapp me-2"></i>Share Now
        </button>
      </div>
    </div>
  </div>
  </div>

<!-- Edit Member Modal -->
<div class="modal fade" id="editMemberModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">
          <i class="fas fa-user-edit text-primary me-2"></i>Edit Member
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" id="editMemberForm" class="needs-validation" novalidate>
        <?php echo csrf_input(); ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" id="edit_id">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Full Name <span class="text-danger">*</span></label>
            <input type="text" name="name" id="edit_name" class="form-control" required>
            <div class="invalid-feedback">Please provide a name.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Phone Number <span class="text-danger">*</span></label>
            <input type="tel" name="phone" id="edit_phone" class="form-control" required>
            <div class="invalid-feedback">Please provide a phone number.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Email Address <span class="text-danger">*</span></label>
            <input type="email" name="email" id="edit_email" class="form-control" required>
            <div class="invalid-feedback">Please provide a valid email.</div>
          </div>
          <div class="row">
            <div class="col-md-6">
              <label class="form-label">Role</label>
              <select name="role" id="edit_role" class="form-select">
                <option value="registrar">Registrar</option>
                <option value="admin">Admin</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Status</label>
              <select name="active" id="edit_active" class="form-select">
                <option value="1">Active</option>
                <option value="0">Inactive</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-save me-2"></i>Save Changes
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Hidden forms for actions -->
<form method="post" id="resetCodeForm" class="d-none">
  <?php echo csrf_input(); ?>
  <input type="hidden" name="action" value="reset_code">
  <input type="hidden" name="id" id="reset_id">
</form>

<form method="post" id="toggleStatusForm" class="d-none">
  <?php echo csrf_input(); ?>
  <input type="hidden" name="action" id="toggle_action">
  <input type="hidden" name="id" id="toggle_id">
</form>



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
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="../assets/admin.js"></script>
<script src="assets/members.js"></script>

<script>
// Show member statistics modal
async function showMemberStats(userId, memberName) {
    const modal = new bootstrap.Modal(document.getElementById('memberStatsModal'));
    const modalTitle = document.getElementById('memberStatsModalLabel');
    const modalContent = document.getElementById('memberStatsContent');
    
    // Update modal title
    modalTitle.innerHTML = `<i class="fas fa-chart-bar me-2"></i>${memberName} - Performance Statistics`;
    
    // Show loading state
    modalContent.innerHTML = `
        <div class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-3 text-muted">Loading member statistics...</p>
        </div>
    `;
    
    // Show modal
    modal.show();
    
    try {
        // Fetch member statistics
        const response = await fetch(`../../api/member_stats.php?user_id=${userId}`);
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.error || 'Failed to load statistics');
        }
        
        // Render statistics
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

// Render member statistics HTML
function renderMemberStats(data) {
    const stats = data.statistics;
    const pledgeStats = data.pledge_stats;
    const paymentStats = data.payment_stats;
    const user = data.user;
    const recentActivity = data.recent_activity;
    
    // Calculate days since last registration
    let lastRegistrationText = 'Never';
    if (stats.last_registration_date) {
        const daysSince = stats.days_since_last_registration;
        if (daysSince === 0) {
            lastRegistrationText = 'Today';
        } else if (daysSince === 1) {
            lastRegistrationText = '1 day ago';
        } else {
            lastRegistrationText = `${daysSince} days ago`;
        }
    }
    
    return `
        <!-- Member Info Header -->
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
        
        <!-- Key Performance Metrics -->
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
        
        <!-- Detailed Statistics -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header bg-info text-white">
                        <h6 class="mb-0"><i class="fas fa-hand-holding-heart me-2"></i>Pledge Statistics</h6>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-3">
                                <strong class="text-primary">${pledgeStats.total}</strong>
                                <small class="d-block text-muted">Total</small>
                            </div>
                            <div class="col-3">
                                <strong class="text-success">${pledgeStats.approved}</strong>
                                <small class="d-block text-muted">Approved</small>
                            </div>
                            <div class="col-3">
                                <strong class="text-danger">${pledgeStats.rejected}</strong>
                                <small class="d-block text-muted">Rejected</small>
                            </div>
                            <div class="col-3">
                                <strong class="text-warning">${pledgeStats.pending}</strong>
                                <small class="d-block text-muted">Pending</small>
                            </div>
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
                            <div class="col-3">
                                <strong class="text-primary">${paymentStats.total}</strong>
                                <small class="d-block text-muted">Total</small>
                            </div>
                            <div class="col-3">
                                <strong class="text-success">${paymentStats.approved}</strong>
                                <small class="d-block text-muted">Approved</small>
                            </div>
                            <div class="col-3">
                                <strong class="text-danger">${paymentStats.rejected}</strong>
                                <small class="d-block text-muted">Rejected</small>
                            </div>
                            <div class="col-3">
                                <strong class="text-warning">${paymentStats.pending}</strong>
                                <small class="d-block text-muted">Pending</small>
                            </div>
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
        
        <!-- Activity Summary -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="fas fa-chart-pie me-2"></i>Total Value Generated</h6>
                    </div>
                    <div class="card-body text-center">
                        <h3 class="text-primary">£${new Intl.NumberFormat().format(stats.total_approved_amount)}</h3>
                        <p class="text-muted mb-0">Total approved value from all registrations</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="fas fa-clock me-2"></i>Last Activity</h6>
                    </div>
                    <div class="card-body text-center">
                        <h5 class="text-${stats.days_since_last_registration === null ? 'muted' : (stats.days_since_last_registration <= 7 ? 'success' : 'warning')}">${lastRegistrationText}</h5>
                        <p class="text-muted mb-0">Last registration date</p>
                        ${stats.last_registration_date ? `<small class="text-muted">${new Date(stats.last_registration_date).toLocaleString()}</small>` : ''}
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Recent Activity -->
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
        </div>
        `}
    `;
}

// ==== WhatsApp Sharing for existing registrars ====
let shareTarget = { userId: null, name: '', phone: '', passcode: '' };

async function openShareWhatsAppModal(userId, name, phone) {
  shareTarget = { userId, name, phone, passcode: '' };
  const modal = new bootstrap.Modal(document.getElementById('shareWhatsAppModal'));
  document.getElementById('whatsappMessagePreview').textContent = 'Generating a new login code...';
  modal.show();

  try {
    const body = new URLSearchParams();
    body.set('user_id', String(userId));
    body.set('csrf_token', '<?php echo csrf_token(); ?>');
    const res = await fetch('../../api/member_generate_code.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    });
    const data = await res.json();
    if (!data.success) { throw new Error(data.error || 'Failed to generate code'); }
    shareTarget.passcode = data.passcode;
    // Build message identical to registrar applications page
    const message = buildAmharicRegistrarMessage(shareTarget);
    document.getElementById('whatsappMessagePreview').textContent = message;
  } catch (err) {
    document.getElementById('whatsappMessagePreview').textContent = 'Error: ' + err.message;
  }
}

function buildAmharicRegistrarMessage(d) {
  return `ሰላም ${d.name},

በነገገው ዕለት ለሚኖረው የገቢ ማሰባሰቢያ ፕሮግራም ላይ መዝጋቢ ሆነው ስለተመዘገቡ በልዑል እግዚአብሄር ስም  እናመሰግናለን።

ወደ መመዝገቢያው ሲስተም ከመግባትዎ በፊት ይሄንን ቪዲዮ መመልከት አለብዎ። 
https://youtu.be/4Dscc1tDlsM

ከዛም የመመዝገቢያ ሰዓቱ ሲደርስ የስልክ ቁጥርዎን እና ከታች ያለውን የመግቢያ ኮድ ተጠቅመው ምዝገባውን ይጀምራሉ።

*የመግቢያ ኮድ:*
*${d.passcode}*

ምዝገባውን ለማድረግ የሚከተለውን ሊንክ ይጠቀሙ። 
https://donate.abuneteklehaymanot.org/registrar/index.php

ከምዝገባው ሰዓት በፊት ገብተው ማየት እና መሞከር ይችላሉ።  

ሊ/መ/ቅ አቡነ ተክለሃይማኖት ቤተክርስቲያን የገቢ አሰባሳቢ ኮሚቴ`;
}

function copyRegistrarShareMessage() {
  const msg = document.getElementById('whatsappMessagePreview').textContent || '';
  if (!msg) return;
  const ta = document.createElement('textarea');
  ta.value = msg;
  document.body.appendChild(ta);
  ta.select();
  let success = false;
  try { success = document.execCommand('copy'); } catch (_) {}
  document.body.removeChild(ta);
  if (!success) {
    alert('Copy failed. Please copy manually.');
  }
}

function shareRegistrarOnWhatsApp() {
  if (!shareTarget.passcode) return;
  const message = buildAmharicRegistrarMessage(shareTarget);
  let phoneNumber = (shareTarget.phone || '').replace(/\D/g, '');
  if (phoneNumber.startsWith('07') && phoneNumber.length === 11) {
    phoneNumber = '44' + phoneNumber.substring(1);
  } else if (phoneNumber.startsWith('7') && phoneNumber.length === 10) {
    phoneNumber = '44' + phoneNumber;
  } else if (phoneNumber.startsWith('447') && phoneNumber.length === 12) {
    // ok
  } else if (phoneNumber.startsWith('0')) {
    phoneNumber = '44' + phoneNumber.substring(1);
  } else if (!phoneNumber.startsWith('44')) {
    phoneNumber = '44' + phoneNumber;
  }
  const isMobile = /Android|iPhone|iPad|iPod|webOS|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
  const url = isMobile
    ? `https://wa.me/${phoneNumber}?text=${encodeURIComponent(message)}`
    : `https://web.whatsapp.com/send?phone=${phoneNumber}&text=${encodeURIComponent(message)}`;
  window.open(url, '_blank');
}
</script>
</body>
</html>