<?php
/**
 * Admin - Support Requests Management
 * View and respond to donor support requests
 */

declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../shared/audit_helper.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

$db = db();
$page_title = 'Support Requests';
$current_user = current_user();

$success_message = '';
$error_message = '';

// Check if tables exist
$tables_exist = false;
$table_check = $db->query("SHOW TABLES LIKE 'donor_support_requests'");
if ($table_check && $table_check->num_rows > 0) {
    $tables_exist = true;
}

// Categories and statuses
$categories = [
    'payment' => ['label' => 'Payment', 'icon' => 'fa-credit-card', 'color' => 'primary'],
    'plan' => ['label' => 'Plan', 'icon' => 'fa-calendar-alt', 'color' => 'info'],
    'account' => ['label' => 'Account', 'icon' => 'fa-user-cog', 'color' => 'warning'],
    'general' => ['label' => 'General', 'icon' => 'fa-question-circle', 'color' => 'secondary'],
    'other' => ['label' => 'Other', 'icon' => 'fa-ellipsis-h', 'color' => 'dark']
];

$statuses = [
    'open' => ['label' => 'Open', 'color' => 'warning'],
    'in_progress' => ['label' => 'In Progress', 'color' => 'info'],
    'resolved' => ['label' => 'Resolved', 'color' => 'success'],
    'closed' => ['label' => 'Closed', 'color' => 'secondary']
];

$priorities = [
    'low' => ['label' => 'Low', 'color' => 'secondary'],
    'normal' => ['label' => 'Normal', 'color' => 'primary'],
    'high' => ['label' => 'High', 'color' => 'warning'],
    'urgent' => ['label' => 'Urgent', 'color' => 'danger']
];

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tables_exist) {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $request_id = (int)($_POST['request_id'] ?? 0);
    
    if ($action === 'add_reply' && $request_id > 0) {
        $message = trim($_POST['message'] ?? '');
        $is_internal = isset($_POST['is_internal']) ? 1 : 0;
        
        if (!empty($message)) {
            try {
                $stmt = $db->prepare("
                    INSERT INTO donor_support_replies (request_id, user_id, message, is_internal)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->bind_param('iisi', $request_id, $current_user['id'], $message, $is_internal);
                $stmt->execute();
                $stmt->close();
                
                // Update request status if not already in progress
                $db->query("UPDATE donor_support_requests SET status = 'in_progress' WHERE id = $request_id AND status = 'open'");
                
                log_audit($db, 'reply', 'support_request', $request_id, null, ['internal' => $is_internal], 'admin_portal', $current_user['id']);
                $success_message = $is_internal ? 'Internal note added.' : 'Reply sent to donor.';
            } catch (Exception $e) {
                $error_message = 'Failed to add reply.';
            }
        }
    } elseif ($action === 'update_status' && $request_id > 0) {
        $new_status = $_POST['status'] ?? '';
        if (array_key_exists($new_status, $statuses)) {
            $resolved_at = null;
            $resolved_by = null;
            
            if ($new_status === 'resolved' || $new_status === 'closed') {
                $resolved_at = date('Y-m-d H:i:s');
                $resolved_by = $current_user['id'];
            }
            
            $stmt = $db->prepare("UPDATE donor_support_requests SET status = ?, resolved_at = ?, resolved_by = ? WHERE id = ?");
            $stmt->bind_param('ssii', $new_status, $resolved_at, $resolved_by, $request_id);
            $stmt->execute();
            $stmt->close();
            
            log_audit($db, 'update_status', 'support_request', $request_id, null, ['status' => $new_status], 'admin_portal', $current_user['id']);
            $success_message = 'Status updated to ' . $statuses[$new_status]['label'] . '.';
        }
    } elseif ($action === 'update_priority' && $request_id > 0) {
        $new_priority = $_POST['priority'] ?? '';
        if (array_key_exists($new_priority, $priorities)) {
            $stmt = $db->prepare("UPDATE donor_support_requests SET priority = ? WHERE id = ?");
            $stmt->bind_param('si', $new_priority, $request_id);
            $stmt->execute();
            $stmt->close();
            
            $success_message = 'Priority updated.';
        }
    } elseif ($action === 'assign' && $request_id > 0) {
        $assign_to = (int)($_POST['assign_to'] ?? 0);
        $assign_to = $assign_to > 0 ? $assign_to : null;
        
        $stmt = $db->prepare("UPDATE donor_support_requests SET assigned_to = ? WHERE id = ?");
        $stmt->bind_param('ii', $assign_to, $request_id);
        $stmt->execute();
        $stmt->close();
        
        $success_message = $assign_to ? 'Request assigned.' : 'Assignment removed.';
    } elseif ($action === 'add_note' && $request_id > 0) {
        $note = trim($_POST['admin_notes'] ?? '');
        $stmt = $db->prepare("UPDATE donor_support_requests SET admin_notes = ? WHERE id = ?");
        $stmt->bind_param('si', $note, $request_id);
        $stmt->execute();
        $stmt->close();
        
        $success_message = 'Notes saved.';
    }
}

// View single request
$view_request = null;
$replies = [];
if (isset($_GET['view']) && is_numeric($_GET['view']) && $tables_exist) {
    $view_id = (int)$_GET['view'];
    $stmt = $db->prepare("
        SELECT sr.*, d.name as donor_name, d.phone as donor_phone, d.email as donor_email,
               u.name as assigned_name, ru.name as resolved_by_name
        FROM donor_support_requests sr
        JOIN donors d ON sr.donor_id = d.id
        LEFT JOIN users u ON sr.assigned_to = u.id
        LEFT JOIN users ru ON sr.resolved_by = ru.id
        WHERE sr.id = ?
    ");
    $stmt->bind_param('i', $view_id);
    $stmt->execute();
    $view_request = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($view_request) {
        // Fetch all replies (including internal)
        $replies_stmt = $db->prepare("
            SELECT r.*, u.name as admin_name, d.name as donor_name
            FROM donor_support_replies r
            LEFT JOIN users u ON r.user_id = u.id
            LEFT JOIN donors d ON r.donor_id = d.id
            WHERE r.request_id = ?
            ORDER BY r.created_at ASC
        ");
        $replies_stmt->bind_param('i', $view_id);
        $replies_stmt->execute();
        $replies = $replies_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $replies_stmt->close();
    }
}

// Filters
$filter_status = $_GET['status'] ?? 'all';
$filter_category = $_GET['category'] ?? 'all';
$filter_priority = $_GET['priority'] ?? 'all';

// Fetch requests list
$requests = [];
if ($tables_exist && !$view_request) {
    $where = [];
    $params = [];
    $types = '';
    
    if ($filter_status !== 'all' && array_key_exists($filter_status, $statuses)) {
        $where[] = "sr.status = ?";
        $params[] = $filter_status;
        $types .= 's';
    }
    if ($filter_category !== 'all' && array_key_exists($filter_category, $categories)) {
        $where[] = "sr.category = ?";
        $params[] = $filter_category;
        $types .= 's';
    }
    if ($filter_priority !== 'all' && array_key_exists($filter_priority, $priorities)) {
        $where[] = "sr.priority = ?";
        $params[] = $filter_priority;
        $types .= 's';
    }
    
    $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    $sql = "
        SELECT sr.*, d.name as donor_name, d.phone as donor_phone, u.name as assigned_name,
               (SELECT COUNT(*) FROM donor_support_replies WHERE request_id = sr.id) as reply_count
        FROM donor_support_requests sr
        JOIN donors d ON sr.donor_id = d.id
        LEFT JOIN users u ON sr.assigned_to = u.id
        $where_sql
        ORDER BY 
            CASE sr.status WHEN 'open' THEN 1 WHEN 'in_progress' THEN 2 WHEN 'resolved' THEN 3 ELSE 4 END,
            CASE sr.priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'normal' THEN 3 ELSE 4 END,
            sr.created_at DESC
        LIMIT 100
    ";
    
    if (!empty($params)) {
        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $db->query($sql);
    }
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $requests[] = $row;
        }
    }
}

// Get admin users for assignment
$admins = [];
$admins_query = $db->query("SELECT id, name FROM users WHERE role IN ('admin', 'registrar') AND active = 1 ORDER BY name");
if ($admins_query) {
    while ($row = $admins_query->fetch_assoc()) {
        $admins[] = $row;
    }
}

// Stats
$stats = ['open' => 0, 'in_progress' => 0, 'resolved' => 0, 'closed' => 0];
if ($tables_exist) {
    $stats_query = $db->query("SELECT status, COUNT(*) as cnt FROM donor_support_requests GROUP BY status");
    if ($stats_query) {
        while ($row = $stats_query->fetch_assoc()) {
            $stats[$row['status']] = (int)$row['cnt'];
        }
    }
}

function h($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function timeAgo($datetime) {
    $time = strtotime($datetime);
    $diff = time() - $time;
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j', $time);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo h($page_title); ?> - Admin</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        .request-row { cursor: pointer; transition: background 0.15s; }
        .request-row:hover { background: #f8fafc; }
        .reply-bubble { padding: 1rem; border-radius: 12px; margin-bottom: 1rem; max-width: 90%; }
        .reply-donor { background: #e0f2fe; border-bottom-left-radius: 4px; }
        .reply-admin { background: #f0fdf4; margin-left: auto; border-bottom-right-radius: 4px; }
        .reply-internal { background: #fef3c7; margin-left: auto; border: 1px dashed #d97706; border-bottom-right-radius: 4px; }
        .reply-meta { font-size: 0.75rem; color: #64748b; margin-top: 0.5rem; }
        .conversation-container { max-height: 500px; overflow-y: auto; padding: 1rem; background: #f8fafc; border-radius: 12px; }
        .stat-pill { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.5rem 1rem; border-radius: 20px; font-weight: 600; }
        .priority-urgent { animation: pulse 2s infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.7; } }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="container-fluid p-3 p-md-4">
                
                <!-- Page Header -->
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
                    <div>
                        <h1 class="h3 mb-1">
                            <i class="fas fa-headset text-primary me-2"></i>Support Requests
                        </h1>
                        <p class="text-muted mb-0">Manage donor inquiries and support tickets</p>
                    </div>
                    <div>
                        <a href="donor-portal.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-1"></i>Back to Portal Dashboard
                        </a>
                    </div>
                </div>

                <?php if ($success_message): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle me-2"></i><?php echo h($success_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo h($error_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if (!$tables_exist): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-database me-2"></i>
                    <strong>Setup Required</strong>
                    <p class="mb-0">Run <code>database/donor_support_requests.sql</code> in phpMyAdmin to enable support requests.</p>
                </div>
                
                <?php elseif ($view_request): ?>
                <!-- View Single Request -->
                <div class="mb-3">
                    <a href="support-requests.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i>Back to All Requests
                    </a>
                </div>
                
                <div class="row g-4">
                    <div class="col-lg-8">
                        <!-- Request Details -->
                        <div class="card mb-4">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-ticket-alt me-2"></i>Request #<?php echo $view_request['id']; ?>
                                </h5>
                                <span class="badge bg-<?php echo $statuses[$view_request['status']]['color']; ?> fs-6">
                                    <?php echo $statuses[$view_request['status']]['label']; ?>
                                </span>
                            </div>
                            <div class="card-body">
                                <div class="d-flex gap-2 mb-3">
                                    <span class="badge bg-<?php echo $categories[$view_request['category']]['color']; ?>">
                                        <i class="fas <?php echo $categories[$view_request['category']]['icon']; ?> me-1"></i>
                                        <?php echo $categories[$view_request['category']]['label']; ?>
                                    </span>
                                    <span class="badge bg-<?php echo $priorities[$view_request['priority']]['color']; ?>">
                                        <?php echo $priorities[$view_request['priority']]['label']; ?> Priority
                                    </span>
                                </div>
                                <h4><?php echo h($view_request['subject']); ?></h4>
                                <p class="text-muted mb-0" style="white-space: pre-wrap;"><?php echo h($view_request['message']); ?></p>
                            </div>
                        </div>
                        
                        <!-- Conversation -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0"><i class="fas fa-comments me-2"></i>Conversation</h5>
                            </div>
                            <div class="card-body">
                                <div class="conversation-container mb-4">
                                    <?php if (empty($replies)): ?>
                                    <p class="text-muted text-center py-3">No replies yet</p>
                                    <?php else: ?>
                                    <?php foreach ($replies as $reply): ?>
                                    <div class="reply-bubble <?php echo $reply['donor_id'] ? 'reply-donor' : ($reply['is_internal'] ? 'reply-internal' : 'reply-admin'); ?>">
                                        <?php if ($reply['is_internal']): ?>
                                        <small class="text-warning fw-bold"><i class="fas fa-lock me-1"></i>Internal Note</small><br>
                                        <?php endif; ?>
                                        <div><?php echo nl2br(h($reply['message'])); ?></div>
                                        <div class="reply-meta">
                                            <?php if ($reply['donor_id']): ?>
                                            <i class="fas fa-user me-1"></i><?php echo h($reply['donor_name']); ?> (Donor)
                                            <?php else: ?>
                                            <i class="fas fa-headset me-1"></i><?php echo h($reply['admin_name'] ?? 'Admin'); ?>
                                            <?php endif; ?>
                                            · <?php echo date('M j, g:i A', strtotime($reply['created_at'])); ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if ($view_request['status'] !== 'closed'): ?>
                                <form method="POST">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="action" value="add_reply">
                                    <input type="hidden" name="request_id" value="<?php echo $view_request['id']; ?>">
                                    <div class="mb-3">
                                        <textarea class="form-control" name="message" rows="3" placeholder="Type your reply..." required></textarea>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="is_internal" id="isInternal">
                                            <label class="form-check-label" for="isInternal">
                                                <i class="fas fa-lock me-1"></i>Internal note (not visible to donor)
                                            </label>
                                        </div>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-paper-plane me-1"></i>Send Reply
                                        </button>
                                    </div>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-4">
                        <!-- Donor Info -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h6 class="card-title mb-0"><i class="fas fa-user me-2"></i>Donor</h6>
                            </div>
                            <div class="card-body">
                                <h5><?php echo h($view_request['donor_name']); ?></h5>
                                <p class="mb-1"><i class="fas fa-phone me-2 text-muted"></i><?php echo h($view_request['donor_phone']); ?></p>
                                <?php if ($view_request['donor_email']): ?>
                                <p class="mb-0"><i class="fas fa-envelope me-2 text-muted"></i><?php echo h($view_request['donor_email']); ?></p>
                                <?php endif; ?>
                                <hr>
                                <a href="view-donor.php?id=<?php echo $view_request['donor_id']; ?>" class="btn btn-outline-primary btn-sm w-100">
                                    <i class="fas fa-external-link-alt me-1"></i>View Donor Profile
                                </a>
                            </div>
                        </div>
                        
                        <!-- Actions -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h6 class="card-title mb-0"><i class="fas fa-cog me-2"></i>Actions</h6>
                            </div>
                            <div class="card-body">
                                <form method="POST" class="mb-3">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="request_id" value="<?php echo $view_request['id']; ?>">
                                    <label class="form-label small text-muted">Status</label>
                                    <div class="input-group">
                                        <select name="status" class="form-select">
                                            <?php foreach ($statuses as $key => $s): ?>
                                            <option value="<?php echo $key; ?>" <?php echo $view_request['status'] === $key ? 'selected' : ''; ?>>
                                                <?php echo $s['label']; ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-outline-primary">Update</button>
                                    </div>
                                </form>
                                
                                <form method="POST" class="mb-3">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="action" value="update_priority">
                                    <input type="hidden" name="request_id" value="<?php echo $view_request['id']; ?>">
                                    <label class="form-label small text-muted">Priority</label>
                                    <div class="input-group">
                                        <select name="priority" class="form-select">
                                            <?php foreach ($priorities as $key => $p): ?>
                                            <option value="<?php echo $key; ?>" <?php echo $view_request['priority'] === $key ? 'selected' : ''; ?>>
                                                <?php echo $p['label']; ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-outline-primary">Update</button>
                                    </div>
                                </form>
                                
                                <form method="POST">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="action" value="assign">
                                    <input type="hidden" name="request_id" value="<?php echo $view_request['id']; ?>">
                                    <label class="form-label small text-muted">Assign To</label>
                                    <div class="input-group">
                                        <select name="assign_to" class="form-select">
                                            <option value="0">Unassigned</option>
                                            <?php foreach ($admins as $admin): ?>
                                            <option value="<?php echo $admin['id']; ?>" <?php echo $view_request['assigned_to'] == $admin['id'] ? 'selected' : ''; ?>>
                                                <?php echo h($admin['name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-outline-primary">Assign</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                        
                        <!-- Admin Notes -->
                        <div class="card">
                            <div class="card-header">
                                <h6 class="card-title mb-0"><i class="fas fa-sticky-note me-2"></i>Admin Notes</h6>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="action" value="add_note">
                                    <input type="hidden" name="request_id" value="<?php echo $view_request['id']; ?>">
                                    <textarea class="form-control mb-2" name="admin_notes" rows="4" placeholder="Internal notes..."><?php echo h($view_request['admin_notes']); ?></textarea>
                                    <button type="submit" class="btn btn-outline-secondary btn-sm w-100">
                                        <i class="fas fa-save me-1"></i>Save Notes
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php else: ?>
                <!-- Request List -->
                
                <!-- Stats -->
                <div class="d-flex flex-wrap gap-3 mb-4">
                    <span class="stat-pill bg-warning bg-opacity-10 text-warning">
                        <i class="fas fa-clock"></i><?php echo $stats['open']; ?> Open
                    </span>
                    <span class="stat-pill bg-info bg-opacity-10 text-info">
                        <i class="fas fa-spinner"></i><?php echo $stats['in_progress']; ?> In Progress
                    </span>
                    <span class="stat-pill bg-success bg-opacity-10 text-success">
                        <i class="fas fa-check"></i><?php echo $stats['resolved']; ?> Resolved
                    </span>
                    <span class="stat-pill bg-secondary bg-opacity-10 text-secondary">
                        <i class="fas fa-archive"></i><?php echo $stats['closed']; ?> Closed
                    </span>
                </div>
                
                <!-- Filters -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label small text-muted">Status</label>
                                <select name="status" class="form-select">
                                    <option value="all">All Statuses</option>
                                    <?php foreach ($statuses as $key => $s): ?>
                                    <option value="<?php echo $key; ?>" <?php echo $filter_status === $key ? 'selected' : ''; ?>>
                                        <?php echo $s['label']; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small text-muted">Category</label>
                                <select name="category" class="form-select">
                                    <option value="all">All Categories</option>
                                    <?php foreach ($categories as $key => $c): ?>
                                    <option value="<?php echo $key; ?>" <?php echo $filter_category === $key ? 'selected' : ''; ?>>
                                        <?php echo $c['label']; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small text-muted">Priority</label>
                                <select name="priority" class="form-select">
                                    <option value="all">All Priorities</option>
                                    <?php foreach ($priorities as $key => $p): ?>
                                    <option value="<?php echo $key; ?>" <?php echo $filter_priority === $key ? 'selected' : ''; ?>>
                                        <?php echo $p['label']; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-filter me-1"></i>Apply
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Request Table -->
                <div class="card">
                    <div class="card-body p-0">
                        <?php if (empty($requests)): ?>
                        <div class="text-center text-muted py-5">
                            <i class="fas fa-inbox fa-3x opacity-25 mb-3"></i>
                            <p>No support requests found</p>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>ID</th>
                                        <th>Donor</th>
                                        <th>Subject</th>
                                        <th>Category</th>
                                        <th>Priority</th>
                                        <th>Status</th>
                                        <th>Assigned</th>
                                        <th>Created</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($requests as $req): ?>
                                    <tr class="request-row <?php echo $req['priority'] === 'urgent' ? 'priority-urgent table-danger' : ''; ?>" 
                                        onclick="window.location='support-requests.php?view=<?php echo $req['id']; ?>'">
                                        <td><strong>#<?php echo $req['id']; ?></strong></td>
                                        <td>
                                            <?php echo h($req['donor_name']); ?>
                                            <br><small class="text-muted"><?php echo h($req['donor_phone']); ?></small>
                                        </td>
                                        <td>
                                            <?php echo h(strlen($req['subject']) > 35 ? substr($req['subject'], 0, 35) . '...' : $req['subject']); ?>
                                            <?php if ($req['reply_count'] > 0): ?>
                                            <br><small class="text-muted"><i class="fas fa-comments"></i> <?php echo $req['reply_count']; ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $categories[$req['category']]['color']; ?>">
                                                <?php echo $categories[$req['category']]['label']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $priorities[$req['priority']]['color']; ?>">
                                                <?php echo $priorities[$req['priority']]['label']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $statuses[$req['status']]['color']; ?>">
                                                <?php echo $statuses[$req['status']]['label']; ?>
                                            </span>
                                        </td>
                                        <td><?php echo h($req['assigned_name'] ?? '-'); ?></td>
                                        <td><small><?php echo timeAgo($req['created_at']); ?></small></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
</body>
</html>

