<?php
/**
 * Inbound Callbacks - View and manage donors who called back
 */

declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

require_login();

$db = db();
$user_id = (int)$_SESSION['user']['id'];
$user_name = $_SESSION['user']['name'] ?? 'Agent';

// Handle follow-up action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'mark_followed_up') {
        $callback_id = (int)($_POST['callback_id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        
        if ($callback_id > 0) {
            $stmt = $db->prepare("
                UPDATE twilio_inbound_calls 
                SET agent_followed_up = 1, 
                    followed_up_by = ?, 
                    followed_up_at = NOW(),
                    notes = ?
                WHERE id = ?
            ");
            $stmt->bind_param('isi', $user_id, $notes, $callback_id);
            $stmt->execute();
            $stmt->close();
            
            header('Location: inbound-callbacks.php?success=1');
            exit;
        }
    }
}

// Check if table exists
$tableExists = $db->query("SHOW TABLES LIKE 'twilio_inbound_calls'")->num_rows > 0;

$callbacks = [];
$stats = ['total' => 0, 'pending' => 0, 'followed_up' => 0, 'today' => 0];

if ($tableExists) {
    // Get stats
    $statsQuery = $db->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN agent_followed_up = 0 THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN agent_followed_up = 1 THEN 1 ELSE 0 END) as followed_up,
            SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today
        FROM twilio_inbound_calls
    ");
    if ($statsQuery) {
        $stats = $statsQuery->fetch_assoc();
    }
    
    // Get callbacks
    $filter = $_GET['filter'] ?? 'pending';
    $whereClause = $filter === 'pending' ? 'WHERE ic.agent_followed_up = 0' : 
                   ($filter === 'followed_up' ? 'WHERE ic.agent_followed_up = 1' : '');
    
    $query = "
        SELECT ic.*, 
               d.name as donor_name,
               d.balance as donor_balance,
               d.total_pledged,
               d.phone as donor_phone,
               u.name as followed_up_by_name
        FROM twilio_inbound_calls ic
        LEFT JOIN donors d ON ic.donor_id = d.id
        LEFT JOIN users u ON ic.followed_up_by = u.id
        {$whereClause}
        ORDER BY ic.agent_followed_up ASC, ic.created_at DESC
        LIMIT 100
    ";
    
    $result = $db->query($query);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $callbacks[] = $row;
        }
    }
}

$page_title = 'Inbound Callbacks';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Call Center</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        .callback-card {
            border-left: 4px solid #0a6286;
            transition: all 0.2s;
        }
        .callback-card.pending {
            border-left-color: #f59e0b;
            background: #fffbeb;
        }
        .callback-card.followed-up {
            border-left-color: #22c55e;
            opacity: 0.8;
        }
        .callback-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .stat-card {
            border-radius: 12px;
            padding: 1.25rem;
            text-align: center;
        }
        .stat-card .stat-number {
            font-size: 2rem;
            font-weight: 700;
        }
        .whatsapp-badge {
            background: #25D366;
            color: white;
        }
        .time-ago {
            font-size: 0.8rem;
            color: #64748b;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    
    <div class="admin-main">
        <?php include __DIR__ . '/../includes/topbar.php'; ?>
        
        <div class="admin-content">
            <div class="main-content">
                <div class="container-fluid">
                    <!-- Header -->
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <h1 class="h3 mb-1">
                                <i class="fas fa-phone-volume me-2 text-primary"></i>Inbound Callbacks
                            </h1>
                            <p class="text-muted mb-0">Donors who called back after missing your call</p>
                        </div>
                        <a href="index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-1"></i>Back to Call Center
                        </a>
                    </div>
                    
                    <?php if (isset($_GET['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i>Callback marked as followed up!
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!$tableExists): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        No inbound calls have been received yet. When donors call your Twilio number, they will appear here.
                    </div>
                    <?php else: ?>
                    
                    <!-- Stats -->
                    <div class="row mb-4">
                        <div class="col-6 col-md-3">
                            <div class="stat-card bg-light">
                                <div class="stat-number text-primary"><?php echo (int)$stats['total']; ?></div>
                                <div class="text-muted">Total Callbacks</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="stat-card bg-warning bg-opacity-10">
                                <div class="stat-number text-warning"><?php echo (int)$stats['pending']; ?></div>
                                <div class="text-muted">Pending Follow-up</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="stat-card bg-success bg-opacity-10">
                                <div class="stat-number text-success"><?php echo (int)$stats['followed_up']; ?></div>
                                <div class="text-muted">Followed Up</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="stat-card bg-info bg-opacity-10">
                                <div class="stat-number text-info"><?php echo (int)$stats['today']; ?></div>
                                <div class="text-muted">Today</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filter -->
                    <div class="mb-3">
                        <div class="btn-group" role="group">
                            <a href="?filter=pending" class="btn btn-<?php echo ($_GET['filter'] ?? 'pending') === 'pending' ? 'primary' : 'outline-primary'; ?>">
                                <i class="fas fa-clock me-1"></i>Pending (<?php echo (int)$stats['pending']; ?>)
                            </a>
                            <a href="?filter=followed_up" class="btn btn-<?php echo ($_GET['filter'] ?? '') === 'followed_up' ? 'primary' : 'outline-primary'; ?>">
                                <i class="fas fa-check me-1"></i>Followed Up
                            </a>
                            <a href="?filter=all" class="btn btn-<?php echo ($_GET['filter'] ?? '') === 'all' ? 'primary' : 'outline-primary'; ?>">
                                <i class="fas fa-list me-1"></i>All
                            </a>
                        </div>
                    </div>
                    
                    <!-- Callbacks List -->
                    <?php if (empty($callbacks)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fas fa-inbox fa-3x mb-3"></i>
                        <p>No callbacks found</p>
                    </div>
                    <?php else: ?>
                    <div class="row">
                        <?php foreach ($callbacks as $cb): ?>
                        <div class="col-md-6 col-lg-4 mb-3">
                            <div class="card callback-card <?php echo $cb['agent_followed_up'] ? 'followed-up' : 'pending'; ?>">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div>
                                            <h6 class="card-title mb-1">
                                                <?php if ($cb['donor_id']): ?>
                                                    <a href="../donor-management/view-donor.php?id=<?php echo $cb['donor_id']; ?>" class="text-decoration-none">
                                                        <?php echo htmlspecialchars($cb['donor_name'] ?? 'Unknown'); ?>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted">Unknown Caller</span>
                                                <?php endif; ?>
                                            </h6>
                                            <div class="text-muted small">
                                                <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($cb['caller_phone']); ?>
                                            </div>
                                        </div>
                                        <div>
                                            <?php if ($cb['whatsapp_sent']): ?>
                                            <span class="badge whatsapp-badge" title="WhatsApp sent">
                                                <i class="fab fa-whatsapp"></i>
                                            </span>
                                            <?php endif; ?>
                                            <?php if ($cb['agent_followed_up']): ?>
                                            <span class="badge bg-success">
                                                <i class="fas fa-check"></i> Done
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <?php if ($cb['donor_id'] && $cb['donor_balance'] > 0): ?>
                                    <div class="mb-2">
                                        <span class="badge bg-danger">
                                            Balance: £<?php echo number_format((float)$cb['donor_balance'], 2); ?>
                                        </span>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="time-ago mb-2">
                                        <i class="fas fa-clock me-1"></i>
                                        <?php 
                                        $time = strtotime($cb['created_at']);
                                        $diff = time() - $time;
                                        if ($diff < 3600) {
                                            echo floor($diff / 60) . ' minutes ago';
                                        } elseif ($diff < 86400) {
                                            echo floor($diff / 3600) . ' hours ago';
                                        } else {
                                            echo date('M j, g:i A', $time);
                                        }
                                        ?>
                                    </div>
                                    
                                    <?php if ($cb['agent_followed_up']): ?>
                                    <div class="small text-muted">
                                        <i class="fas fa-user-check me-1"></i>
                                        <?php echo htmlspecialchars($cb['followed_up_by_name'] ?? 'Agent'); ?>
                                        on <?php echo date('M j, g:i A', strtotime($cb['followed_up_at'])); ?>
                                    </div>
                                    <?php if ($cb['notes']): ?>
                                    <div class="small text-muted mt-1">
                                        <i class="fas fa-sticky-note me-1"></i><?php echo htmlspecialchars($cb['notes']); ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php else: ?>
                                    <div class="d-flex gap-2">
                                        <?php if ($cb['donor_id']): ?>
                                        <a href="make-call.php?donor_id=<?php echo $cb['donor_id']; ?>" class="btn btn-sm btn-success">
                                            <i class="fas fa-phone me-1"></i>Call Back
                                        </a>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-sm btn-outline-primary" 
                                                onclick="showFollowUpModal(<?php echo $cb['id']; ?>, '<?php echo htmlspecialchars($cb['donor_name'] ?? $cb['caller_phone']); ?>')">
                                            <i class="fas fa-check me-1"></i>Mark Done
                                        </button>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Follow-up Modal -->
    <div class="modal fade" id="followUpModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="mark_followed_up">
                    <input type="hidden" name="callback_id" id="modalCallbackId">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="fas fa-check-circle text-success me-2"></i>Mark as Followed Up
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Mark callback from <strong id="modalDonorName"></strong> as followed up?</p>
                        <div class="mb-3">
                            <label for="notes" class="form-label">Notes (optional)</label>
                            <textarea class="form-control" name="notes" id="notes" rows="2" 
                                      placeholder="e.g., Called back, discussed payment plan"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-check me-1"></i>Mark as Done
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function showFollowUpModal(callbackId, donorName) {
            document.getElementById('modalCallbackId').value = callbackId;
            document.getElementById('modalDonorName').textContent = donorName;
            new bootstrap.Modal(document.getElementById('followUpModal')).show();
        }
    </script>
</body>
</html>

