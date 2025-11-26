<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../shared/auth.php';
require_once __DIR__ . '/../../../shared/csrf.php';
require_once __DIR__ . '/../../../config/db.php';
require_login();
require_admin();

$page_title = 'SMS Queue';
$current_user = current_user();
$db = db();

$queue_items = [];
$stats = ['pending' => 0, 'processing' => 0, 'failed' => 0];
$error_message = null;
$success_message = $_SESSION['success_message'] ?? null;
unset($_SESSION['success_message']);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'cancel' && isset($_POST['queue_id'])) {
            $queue_id = (int)$_POST['queue_id'];
            $stmt = $db->prepare("UPDATE sms_queue SET status = 'cancelled' WHERE id = ? AND status = 'pending'");
            $stmt->bind_param('i', $queue_id);
            $stmt->execute();
            $_SESSION['success_message'] = 'Message cancelled.';
        }
        
        if ($action === 'cancel_all') {
            $db->query("UPDATE sms_queue SET status = 'cancelled' WHERE status = 'pending'");
            $_SESSION['success_message'] = 'All pending messages cancelled.';
        }
        
        if ($action === 'retry' && isset($_POST['queue_id'])) {
            $queue_id = (int)$_POST['queue_id'];
            $stmt = $db->prepare("UPDATE sms_queue SET status = 'pending', attempts = 0, error_message = NULL WHERE id = ?");
            $stmt->bind_param('i', $queue_id);
            $stmt->execute();
            $_SESSION['success_message'] = 'Message queued for retry.';
        }
        
        header('Location: queue.php');
        exit;
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

try {
    // Get queue stats
    $stats_result = $db->query("
        SELECT status, COUNT(*) as count 
        FROM sms_queue 
        WHERE status IN ('pending', 'processing', 'failed')
        GROUP BY status
    ");
    while ($row = $stats_result->fetch_assoc()) {
        $stats[$row['status']] = (int)$row['count'];
    }
    
    // Get queue items
    $result = $db->query("
        SELECT q.*, d.name as donor_name, t.name as template_name
        FROM sms_queue q
        LEFT JOIN donors d ON q.donor_id = d.id
        LEFT JOIN sms_templates t ON q.template_id = t.id
        WHERE q.status IN ('pending', 'processing', 'failed')
        ORDER BY 
            CASE q.status 
                WHEN 'processing' THEN 1 
                WHEN 'pending' THEN 2 
                WHEN 'failed' THEN 3 
            END,
            q.priority DESC, 
            q.created_at ASC
        LIMIT 100
    ");
    
    while ($row = $result->fetch_assoc()) {
        $queue_items[] = $row;
    }
    
} catch (Exception $e) {
    $error_message = $e->getMessage();
}

$total_items = $stats['pending'] + $stats['processing'] + $stats['failed'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $page_title; ?> - Fundraising Admin</title>
    <link rel="icon" type="image/svg+xml" href="../../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../../assets/admin.css">
    <link rel="stylesheet" href="../assets/donor-management.css">
    <style>
        .queue-stat {
            background: white;
            border-radius: 12px;
            padding: 1rem 1.25rem;
            border: 1px solid #e5e7eb;
            text-align: center;
        }
        .queue-stat-value {
            font-size: 1.75rem;
            font-weight: 800;
            line-height: 1;
        }
        .queue-stat-label {
            font-size: 0.75rem;
            color: #64748b;
            font-weight: 600;
            margin-top: 0.25rem;
        }
        .priority-badge {
            width: 28px;
            height: 28px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.8125rem;
        }
        .priority-high { background: #fee2e2; color: #dc2626; }
        .priority-normal { background: #dbeafe; color: #2563eb; }
        .priority-low { background: #f1f5f9; color: #64748b; }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="container-fluid p-3 p-md-4">
                
                <!-- Header -->
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 gap-3">
                    <div>
                        <nav aria-label="breadcrumb">
                            <ol class="breadcrumb mb-2">
                                <li class="breadcrumb-item"><a href="index.php">SMS Dashboard</a></li>
                                <li class="breadcrumb-item active">Queue</li>
                            </ol>
                        </nav>
                        <h1 class="h3 mb-0">
                            <i class="fas fa-list-ol text-warning me-2"></i>SMS Queue
                        </h1>
                    </div>
                    <?php if ($stats['pending'] > 0): ?>
                        <form method="POST" onsubmit="return confirm('Cancel ALL pending messages?');">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="action" value="cancel_all">
                            <button type="submit" class="btn btn-outline-danger">
                                <i class="fas fa-times-circle me-2"></i>Cancel All Pending
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
                
                <?php if ($error_message): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($success_message): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Stats -->
                <div class="row g-3 mb-4">
                    <div class="col-4">
                        <div class="queue-stat">
                            <div class="queue-stat-value text-warning"><?php echo number_format($stats['pending']); ?></div>
                            <div class="queue-stat-label">Pending</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="queue-stat">
                            <div class="queue-stat-value text-info"><?php echo number_format($stats['processing']); ?></div>
                            <div class="queue-stat-label">Processing</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="queue-stat">
                            <div class="queue-stat-value text-danger"><?php echo number_format($stats['failed']); ?></div>
                            <div class="queue-stat-label">Failed</div>
                        </div>
                    </div>
                </div>
                
                <!-- Queue List -->
                <?php if (empty($queue_items)): ?>
                    <div class="card">
                        <div class="card-body text-center py-5">
                            <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                            <h4>Queue is Empty</h4>
                            <p class="text-muted">All messages have been processed.</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 50px;">Pri</th>
                                        <th>Recipient</th>
                                        <th class="d-none d-md-table-cell">Message</th>
                                        <th>Status</th>
                                        <th class="d-none d-lg-table-cell">Source</th>
                                        <th style="width: 100px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($queue_items as $item): ?>
                                        <tr>
                                            <td>
                                                <span class="priority-badge priority-<?php 
                                                    echo $item['priority'] >= 8 ? 'high' : ($item['priority'] >= 5 ? 'normal' : 'low'); 
                                                ?>">
                                                    <?php echo $item['priority']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($item['donor_name'] ?? 'Unknown'); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($item['phone_number']); ?></small>
                                            </td>
                                            <td class="d-none d-md-table-cell">
                                                <small class="text-muted">
                                                    <?php echo htmlspecialchars(substr($item['message_content'] ?? '', 0, 40)); ?>...
                                                </small>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php 
                                                    echo match($item['status']) {
                                                        'processing' => 'info',
                                                        'pending' => 'warning',
                                                        'failed' => 'danger',
                                                        default => 'secondary'
                                                    };
                                                ?>">
                                                    <?php echo ucfirst($item['status']); ?>
                                                </span>
                                                <?php if ($item['attempts'] > 0): ?>
                                                    <small class="text-muted d-block">
                                                        Attempt <?php echo $item['attempts']; ?>/<?php echo $item['max_attempts']; ?>
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="d-none d-lg-table-cell">
                                                <small><?php echo ucwords(str_replace('_', ' ', $item['source_type'] ?? '')); ?></small>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <?php if ($item['status'] === 'pending'): ?>
                                                        <form method="POST" class="d-inline">
                                                            <?php echo csrf_input(); ?>
                                                            <input type="hidden" name="action" value="cancel">
                                                            <input type="hidden" name="queue_id" value="<?php echo $item['id']; ?>">
                                                            <button type="submit" class="btn btn-outline-danger btn-sm" title="Cancel">
                                                                <i class="fas fa-times"></i>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <?php if ($item['status'] === 'failed'): ?>
                                                        <form method="POST" class="d-inline">
                                                            <?php echo csrf_input(); ?>
                                                            <input type="hidden" name="action" value="retry">
                                                            <input type="hidden" name="queue_id" value="<?php echo $item['id']; ?>">
                                                            <button type="submit" class="btn btn-outline-primary btn-sm" title="Retry">
                                                                <i class="fas fa-redo"></i>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="mt-3 text-muted small">
                    <i class="fas fa-info-circle me-1"></i>
                    Queue is processed automatically every 5 minutes by cron job.
                </div>
                
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/admin.js"></script>
</body>
</html>

