<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_admin();

$page_title = 'Registrar Applications';
$db = db();
$msg = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $appId = (int)($_POST['app_id'] ?? 0);
    
    if ($action === 'approve' && $appId > 0) {
        // Get application details
        $stmt = $db->prepare('SELECT * FROM registrar_applications WHERE id = ? AND status = "pending"');
        $stmt->bind_param('i', $appId);
        $stmt->execute();
        $app = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($app) {
            // Generate a random 6-digit passcode
            $passcode = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $hash = password_hash($passcode, PASSWORD_DEFAULT);
            
            // Begin transaction
            $db->begin_transaction();
            
            try {
                // Create user account
                $stmt = $db->prepare('INSERT INTO users (name, phone, email, role, password_hash, active, created_at) VALUES (?, ?, ?, "registrar", ?, 1, NOW())');
                $stmt->bind_param('ssss', $app['name'], $app['phone'], $app['email'], $hash);
                $stmt->execute();
                $userId = $db->insert_id;
                $stmt->close();
                
                // Update application status
                $adminId = current_user()['id'];
                $stmt = $db->prepare('UPDATE registrar_applications SET status = "approved", passcode = ?, approved_by_user_id = ?, approved_at = NOW() WHERE id = ?');
                $stmt->bind_param('sii', $passcode, $adminId, $appId);
                $stmt->execute();
                $stmt->close();
                
                $db->commit();
                $msg = "Application approved successfully! Registrar account created with passcode: <strong>{$passcode}</strong><br><small class='text-muted'>Please share this passcode with {$app['name']} securely.</small>";
            } catch (Exception $e) {
                $db->rollback();
                $error = 'Failed to approve application: ' . $e->getMessage();
            }
        } else {
            $error = 'Application not found or already processed.';
        }
    } elseif ($action === 'reject' && $appId > 0) {
        $notes = trim($_POST['notes'] ?? '');
        $adminId = current_user()['id'];
        
        $stmt = $db->prepare('UPDATE registrar_applications SET status = "rejected", notes = ?, approved_by_user_id = ?, approved_at = NOW() WHERE id = ? AND status = "pending"');
        $stmt->bind_param('sii', $notes, $adminId, $appId);
        
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $msg = 'Application rejected successfully.';
        } else {
            $error = 'Failed to reject application or application not found.';
        }
        $stmt->close();
    }
}

// Get applications with pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$status = $_GET['status'] ?? 'all';
$statusWhere = '';
$statusParam = '';

if (in_array($status, ['pending', 'approved', 'rejected'])) {
    $statusWhere = 'WHERE ra.status = ?';
    $statusParam = $status;
}

// Get total count
$countSql = "SELECT COUNT(*) as total FROM registrar_applications ra $statusWhere";
$stmt = $db->prepare($countSql);
if ($statusParam) {
    $stmt->bind_param('s', $statusParam);
}
$stmt->execute();
$totalCount = $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$totalPages = ceil($totalCount / $perPage);

// Get applications
$sql = "
    SELECT ra.*, u.name as approved_by_name 
    FROM registrar_applications ra 
    LEFT JOIN users u ON ra.approved_by_user_id = u.id 
    $statusWhere
    ORDER BY ra.created_at DESC 
    LIMIT ? OFFSET ?
";

$stmt = $db->prepare($sql);
if ($statusParam) {
    $stmt->bind_param('sii', $statusParam, $perPage, $offset);
} else {
    $stmt->bind_param('ii', $perPage, $offset);
}
$stmt->execute();
$applications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Helper function
function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($page_title); ?> - Admin</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/theme.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <link rel="stylesheet" href="assets/registrar-applications.css">
</head>
<body>
    <div class="admin-layout">
        <?php include '../includes/sidebar.php'; ?>
        
        <main class="main-content">
            <?php include '../includes/topbar.php'; ?>
            
            <div class="content-wrapper">
                <div class="content-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h1 class="content-title">
                                <i class="fas fa-user-plus me-2"></i>
                                Registrar Applications
                            </h1>
                            <p class="content-subtitle">Manage registrar registration requests</p>
                        </div>
                    </div>
                </div>
                
                <div class="content-body">
                    <?php if ($msg): ?>
                        <div class="alert alert-success alert-dismissible fade show">
                            <i class="fas fa-check-circle me-2"></i>
                            <?php echo $msg; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <?php echo h($error); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Filter tabs -->
                    <div class="card mb-4">
                        <div class="card-body">
                            <ul class="nav nav-pills">
                                <li class="nav-item">
                                    <a class="nav-link <?php echo $status === 'all' ? 'active' : ''; ?>" 
                                       href="?status=all">
                                        <i class="fas fa-list me-1"></i>All Applications
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link <?php echo $status === 'pending' ? 'active' : ''; ?>" 
                                       href="?status=pending">
                                        <i class="fas fa-clock me-1"></i>Pending
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link <?php echo $status === 'approved' ? 'active' : ''; ?>" 
                                       href="?status=approved">
                                        <i class="fas fa-check me-1"></i>Approved
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link <?php echo $status === 'rejected' ? 'active' : ''; ?>" 
                                       href="?status=rejected">
                                        <i class="fas fa-times me-1"></i>Rejected
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </div>
                    
                    <!-- Applications list -->
                    <?php if (empty($applications)): ?>
                        <div class="card border-0 shadow-sm">
                            <div class="card-body empty-state">
                                <div class="empty-state-icon">
                                    <i class="fas fa-user-plus"></i>
                                </div>
                                <h5 class="empty-state-title">No Applications Found</h5>
                                <p class="empty-state-desc">
                                    <?php if ($status !== 'all'): ?>
                                        There are no <?php echo h($status); ?> registrar applications to display.
                                        <br><a href="?status=all" class="text-decoration-none">View all applications</a>
                                    <?php else: ?>
                                        No registrar applications have been submitted yet. 
                                        <br>Applications will appear here when users submit registrar requests.
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($applications as $app): ?>
                            <div class="application-card">
                                <div class="card-body">
                                    <!-- Application Header -->
                                    <div class="application-header">
                                        <div class="d-flex align-items-center flex-grow-1">
                                            <div class="applicant-avatar">
                                                <?php echo strtoupper(substr(h($app['name']), 0, 1)); ?>
                                            </div>
                                            <div class="applicant-info flex-grow-1">
                                                <h5 class="mb-0">
                                                    <?php echo h($app['name']); ?>
                                                    <span class="badge status-badge status-<?php echo h($app['status']); ?> ms-2">
                                                        <?php echo ucfirst(h($app['status'])); ?>
                                                    </span>
                                                </h5>
                                            </div>
                                        </div>
                                        
                                        <?php if ($app['status'] === 'pending'): ?>
                                            <div class="action-buttons">
                                                <form method="post" class="d-inline">
                                                    <?php echo csrf_input(); ?>
                                                    <input type="hidden" name="action" value="approve">
                                                    <input type="hidden" name="app_id" value="<?php echo $app['id']; ?>">
                                                    <button type="submit" class="btn btn-approve" 
                                                            onclick="return confirm('Are you sure you want to approve this application? This will create a registrar account.')">
                                                        <i class="fas fa-check"></i>
                                                        Approve
                                                    </button>
                                                </form>
                                                
                                                <button type="button" class="btn btn-reject" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#rejectModal<?php echo $app['id']; ?>">
                                                    <i class="fas fa-times"></i>
                                                    Reject
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Application Details -->
                                    <div class="application-details">
                                        <div class="detail-item">
                                            <div class="detail-icon email">
                                                <i class="fas fa-envelope"></i>
                                            </div>
                                            <div class="detail-content">
                                                <div class="detail-label">Email Address</div>
                                                <div class="detail-value"><?php echo h($app['email']); ?></div>
                                            </div>
                                        </div>
                                        
                                        <div class="detail-item">
                                            <div class="detail-icon phone">
                                                <i class="fas fa-phone"></i>
                                            </div>
                                            <div class="detail-content">
                                                <div class="detail-label">Phone Number</div>
                                                <div class="detail-value"><?php echo h($app['phone']); ?></div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Timeline -->
                                    <div class="application-timeline">
                                        <div class="timeline-item">
                                            <div class="timeline-icon submitted">
                                                <i class="fas fa-paper-plane"></i>
                                            </div>
                                            <div class="timeline-content">
                                                <div class="timeline-title">Application Submitted</div>
                                                <div class="timeline-desc">
                                                    <?php echo date('M j, Y \a\t g:i A', strtotime($app['created_at'])); ?>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <?php if ($app['approved_at']): ?>
                                            <div class="timeline-item">
                                                <div class="timeline-icon processed">
                                                    <i class="fas fa-<?php echo $app['status'] === 'approved' ? 'check' : 'times'; ?>"></i>
                                                </div>
                                                <div class="timeline-content">
                                                    <div class="timeline-title">
                                                        Application <?php echo ucfirst(h($app['status'])); ?>
                                                    </div>
                                                    <div class="timeline-desc">
                                                        <?php echo date('M j, Y \a\t g:i A', strtotime($app['approved_at'])); ?>
                                                        <?php if ($app['approved_by_name']): ?>
                                                            by <?php echo h($app['approved_by_name']); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if ($app['passcode'] && $app['status'] === 'approved'): ?>
                                        <div class="passcode-display">
                                            <div class="passcode-label">Generated Login Passcode</div>
                                            <div class="passcode-value"><?php echo h($app['passcode']); ?></div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($app['notes']): ?>
                                        <div class="alert alert-info border-0 mt-3">
                                            <div class="d-flex align-items-start">
                                                <i class="fas fa-sticky-note me-2 mt-1"></i>
                                                <div>
                                                    <strong>Admin Notes:</strong><br>
                                                    <?php echo h($app['notes']); ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Enhanced Reject Modal -->
                            <?php if ($app['status'] === 'pending'): ?>
                                <div class="modal fade" id="rejectModal<?php echo $app['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog modal-dialog-centered">
                                        <div class="modal-content">
                                            <div class="modal-header border-0 pb-0">
                                                <h5 class="modal-title">
                                                    <i class="fas fa-exclamation-triangle text-warning me-2"></i>
                                                    Reject Application
                                                </h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form method="post">
                                                <div class="modal-body">
                                                    <?php echo csrf_input(); ?>
                                                    <input type="hidden" name="action" value="reject">
                                                    <input type="hidden" name="app_id" value="<?php echo $app['id']; ?>">
                                                    
                                                    <div class="text-center mb-4">
                                                        <div class="applicant-avatar mx-auto mb-3">
                                                            <?php echo strtoupper(substr(h($app['name']), 0, 1)); ?>
                                                        </div>
                                                        <p class="mb-0">Are you sure you want to reject the application from</p>
                                                        <strong class="fs-5"><?php echo h($app['name']); ?></strong>?
                                                    </div>
                                                    
                                                    <div class="mb-3">
                                                        <label for="notes<?php echo $app['id']; ?>" class="form-label">
                                                            <i class="fas fa-comment me-1"></i>
                                                            Reason for Rejection (Optional)
                                                        </label>
                                                        <textarea name="notes" id="notes<?php echo $app['id']; ?>" 
                                                                  class="form-control" rows="3" 
                                                                  placeholder="Provide a reason for the rejection to help the applicant understand..."></textarea>
                                                        <div class="form-text">This note will be recorded for administrative purposes.</div>
                                                    </div>
                                                </div>
                                                <div class="modal-footer border-0 pt-0">
                                                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">
                                                        <i class="fas fa-arrow-left me-1"></i>Cancel
                                                    </button>
                                                    <button type="submit" class="btn btn-danger">
                                                        <i class="fas fa-times me-1"></i>Reject Application
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        
                        <!-- Pagination -->
                        <?php if ($totalPages > 1): ?>
                            <nav aria-label="Applications pagination">
                                <ul class="pagination justify-content-center">
                                    <?php if ($page > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?page=<?php echo $page - 1; ?>&status=<?php echo urlencode($status); ?>">
                                                <i class="fas fa-chevron-left"></i>
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                    
                                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $i; ?>&status=<?php echo urlencode($status); ?>">
                                                <?php echo $i; ?>
                                            </a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <?php if ($page < $totalPages): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?page=<?php echo $page + 1; ?>&status=<?php echo urlencode($status); ?>">
                                                <i class="fas fa-chevron-right"></i>
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/admin.js"></script>
    
    <script>
        // Enhanced registrar applications UI interactions
        (function() {
            'use strict';
            
            // Loading states for form submissions
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const submitBtn = this.querySelector('button[type="submit"]');
                    if (submitBtn && !submitBtn.classList.contains('loading')) {
                        submitBtn.classList.add('loading');
                        submitBtn.disabled = true;
                        
                        // Re-enable after timeout as fallback
                        setTimeout(() => {
                            submitBtn.classList.remove('loading');
                            submitBtn.disabled = false;
                        }, 10000);
                    }
                });
            });
            
            // Enhanced confirm dialogs for approve actions
            const approveButtons = document.querySelectorAll('.btn-approve');
            approveButtons.forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    
                    const form = this.closest('form');
                    const applicantName = this.closest('.application-card')
                        .querySelector('.applicant-info h5')
                        .textContent.split('\n')[0].trim();
                    
                    // Create custom confirmation
                    const confirmed = confirm(
                        `Approve ${applicantName}'s application?\n\n` +
                        `This will:\n` +
                        `• Create a registrar account\n` +
                        `• Generate a 6-digit login passcode\n` +
                        `• Grant access to the registrar system\n\n` +
                        `Continue?`
                    );
                    
                    if (confirmed) {
                        form.submit();
                    }
                });
            });
            
            // Auto-select and copy passcode when clicked
            const passcodes = document.querySelectorAll('.passcode-value');
            passcodes.forEach(passcode => {
                passcode.style.cursor = 'pointer';
                passcode.title = 'Click to copy passcode';
                
                passcode.addEventListener('click', function() {
                    // Select the text
                    const range = document.createRange();
                    range.selectNode(this);
                    window.getSelection().removeAllRanges();
                    window.getSelection().addRange(range);
                    
                    // Copy to clipboard
                    try {
                        document.execCommand('copy');
                        
                        // Show feedback
                        const originalText = this.textContent;
                        this.textContent = 'Copied!';
                        this.style.color = '#059669';
                        
                        setTimeout(() => {
                            this.textContent = originalText;
                            this.style.color = '';
                            window.getSelection().removeAllRanges();
                        }, 1500);
                    } catch (err) {
                        console.log('Copy failed:', err);
                    }
                });
            });
            
            // Smooth animations for new applications (if any are added dynamically)
            const applications = document.querySelectorAll('.application-card');
            applications.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
            });
            
            // Enhanced modal interactions
            const rejectModals = document.querySelectorAll('[id^="rejectModal"]');
            rejectModals.forEach(modal => {
                modal.addEventListener('shown.bs.modal', function() {
                    // Focus on textarea when modal opens
                    const textarea = this.querySelector('textarea');
                    if (textarea) {
                        setTimeout(() => textarea.focus(), 100);
                    }
                });
                
                modal.addEventListener('hidden.bs.modal', function() {
                    // Clear form when modal closes
                    const form = this.querySelector('form');
                    if (form) {
                        const textarea = form.querySelector('textarea');
                        if (textarea) textarea.value = '';
                    }
                });
            });
            
            // Auto-refresh indicator (optional)
            let refreshTimer;
            function showRefreshIndicator() {
                // Only show if there are pending applications
                const pendingCount = document.querySelectorAll('.status-pending').length;
                if (pendingCount > 0) {
                    // Could add a subtle refresh indicator here
                }
            }
            
            // Filter tab enhancements
            const filterTabs = document.querySelectorAll('.nav-pills .nav-link');
            filterTabs.forEach(tab => {
                tab.addEventListener('click', function(e) {
                    // Add loading state during navigation
                    if (!this.classList.contains('active')) {
                        this.style.opacity = '0.7';
                        this.style.pointerEvents = 'none';
                    }
                });
            });
            
            // Keyboard shortcuts (optional enhancement)
            document.addEventListener('keydown', function(e) {
                // Ctrl/Cmd + R to refresh (prevent default and show custom refresh)
                if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
                    e.preventDefault();
                    window.location.reload();
                }
            });
            
        })();
    </script>
</body>
</html>
