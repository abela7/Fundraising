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
    <style>
        .application-card {
            border: 1px solid #ddd;
            border-radius: 8px;
            margin-bottom: 1rem;
            transition: box-shadow 0.2s;
        }
        .application-card:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .status-badge {
            font-size: 0.8rem;
            padding: 0.25rem 0.5rem;
        }
        .status-pending { background-color: #fff3cd; color: #856404; }
        .status-approved { background-color: #d1eddd; color: #155724; }
        .status-rejected { background-color: #f8d7da; color: #721c24; }
    </style>
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
                        <div class="card">
                            <div class="card-body text-center py-5">
                                <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No applications found</h5>
                                <p class="text-muted">There are no registrar applications to display.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($applications as $app): ?>
                            <div class="application-card">
                                <div class="card-body">
                                    <div class="row align-items-center">
                                        <div class="col-md-8">
                                            <div class="d-flex align-items-center mb-2">
                                                <h5 class="mb-0 me-3"><?php echo h($app['name']); ?></h5>
                                                <span class="badge status-badge status-<?php echo h($app['status']); ?>">
                                                    <?php echo ucfirst(h($app['status'])); ?>
                                                </span>
                                            </div>
                                            
                                            <div class="row text-muted">
                                                <div class="col-sm-6">
                                                    <i class="fas fa-envelope me-1"></i>
                                                    <?php echo h($app['email']); ?>
                                                </div>
                                                <div class="col-sm-6">
                                                    <i class="fas fa-phone me-1"></i>
                                                    <?php echo h($app['phone']); ?>
                                                </div>
                                            </div>
                                            
                                            <small class="text-muted">
                                                <i class="fas fa-calendar me-1"></i>
                                                Applied: <?php echo date('M j, Y g:i A', strtotime($app['created_at'])); ?>
                                                
                                                <?php if ($app['approved_at']): ?>
                                                    <br>
                                                    <i class="fas fa-user-check me-1"></i>
                                                    Processed: <?php echo date('M j, Y g:i A', strtotime($app['approved_at'])); ?>
                                                    <?php if ($app['approved_by_name']): ?>
                                                        by <?php echo h($app['approved_by_name']); ?>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                
                                                <?php if ($app['passcode'] && $app['status'] === 'approved'): ?>
                                                    <br>
                                                    <i class="fas fa-key me-1"></i>
                                                    Passcode: <strong><?php echo h($app['passcode']); ?></strong>
                                                <?php endif; ?>
                                                
                                                <?php if ($app['notes']): ?>
                                                    <br>
                                                    <i class="fas fa-sticky-note me-1"></i>
                                                    Notes: <?php echo h($app['notes']); ?>
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                        
                                        <div class="col-md-4 text-end">
                                            <?php if ($app['status'] === 'pending'): ?>
                                                <div class="btn-group-vertical d-grid gap-2">
                                                    <form method="post" class="d-inline">
                                                        <?php echo csrf_input(); ?>
                                                        <input type="hidden" name="action" value="approve">
                                                        <input type="hidden" name="app_id" value="<?php echo $app['id']; ?>">
                                                        <button type="submit" class="btn btn-success btn-sm w-100" 
                                                                onclick="return confirm('Are you sure you want to approve this application? This will create a registrar account.')">
                                                            <i class="fas fa-check me-1"></i>Approve
                                                        </button>
                                                    </form>
                                                    
                                                    <button type="button" class="btn btn-danger btn-sm w-100" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#rejectModal<?php echo $app['id']; ?>">
                                                        <i class="fas fa-times me-1"></i>Reject
                                                    </button>
                                                </div>
                                                
                                                <!-- Reject Modal -->
                                                <div class="modal fade" id="rejectModal<?php echo $app['id']; ?>" tabindex="-1">
                                                    <div class="modal-dialog">
                                                        <div class="modal-content">
                                                            <div class="modal-header">
                                                                <h5 class="modal-title">Reject Application</h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                            </div>
                                                            <form method="post">
                                                                <div class="modal-body">
                                                                    <?php echo csrf_input(); ?>
                                                                    <input type="hidden" name="action" value="reject">
                                                                    <input type="hidden" name="app_id" value="<?php echo $app['id']; ?>">
                                                                    
                                                                    <p>Are you sure you want to reject the application from <strong><?php echo h($app['name']); ?></strong>?</p>
                                                                    
                                                                    <div class="mb-3">
                                                                        <label for="notes<?php echo $app['id']; ?>" class="form-label">Reason (optional)</label>
                                                                        <textarea name="notes" id="notes<?php echo $app['id']; ?>" 
                                                                                  class="form-control" rows="3" 
                                                                                  placeholder="Enter reason for rejection..."></textarea>
                                                                    </div>
                                                                </div>
                                                                <div class="modal-footer">
                                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                    <button type="submit" class="btn btn-danger">
                                                                        <i class="fas fa-times me-1"></i>Reject Application
                                                                    </button>
                                                                </div>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
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
</body>
</html>
