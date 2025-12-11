<?php
/**
 * Donor Message History
 * 
 * Comprehensive view of all messages (SMS + WhatsApp) sent to a donor
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../services/MessagingHelper.php';

require_admin();

$db = db();
$msg = new MessagingHelper($db);

// Get donor ID
$donorId = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0;

if (!$donorId) {
    header('Location: /admin/donor-management/donors.php');
    exit;
}

// Get donor info
$stmt = $db->prepare("SELECT id, name, phone FROM donors WHERE id = ?");
$stmt->bind_param('i', $donorId);
$stmt->execute();
$donor = $stmt->get_result()->fetch_assoc();

if (!$donor) {
    die('Donor not found');
}

// Get message history
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$channelFilter = $_GET['channel'] ?? null;

$messages = $msg->getDonorMessageHistory($donorId, $limit, $offset, $channelFilter);
$stats = $msg->getDonorMessageStats($donorId);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Message History - <?= htmlspecialchars($donor['name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .channel-badge { font-size: 0.75rem; }
        .status-badge { font-size: 0.75rem; }
        .message-preview { max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    </style>
</head>
<body>
    <div class="container-fluid mt-4">
        <div class="row mb-4">
            <div class="col">
                <h2>
                    <i class="fas fa-envelope me-2"></i>
                    Message History: <?= htmlspecialchars($donor['name']) ?>
                </h2>
                <p class="text-muted">Phone: <?= htmlspecialchars($donor['phone']) ?></p>
            </div>
            <div class="col-auto">
                <a href="/admin/donor-management/view-donor.php?id=<?= $donorId ?>" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-1"></i> Back to Donor
                </a>
            </div>
        </div>
        
        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body">
                        <h6 class="text-muted">Total Messages</h6>
                        <h3><?= number_format($stats['total_messages'] ?? 0) ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body">
                        <h6 class="text-muted">SMS</h6>
                        <h3><?= number_format($stats['sms_count'] ?? 0) ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body">
                        <h6 class="text-muted">WhatsApp</h6>
                        <h3><?= number_format($stats['whatsapp_count'] ?? 0) ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body">
                        <h6 class="text-muted">Delivered</h6>
                        <h3 class="text-success"><?= number_format($stats['delivered_count'] ?? 0) ?></h3>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <input type="hidden" name="donor_id" value="<?= $donorId ?>">
                    <div class="col-md-3">
                        <label class="form-label">Channel</label>
                        <select name="channel" class="form-select">
                            <option value="">All Channels</option>
                            <option value="sms" <?= $channelFilter === 'sms' ? 'selected' : '' ?>>SMS Only</option>
                            <option value="whatsapp" <?= $channelFilter === 'whatsapp' ? 'selected' : '' ?>>WhatsApp Only</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Limit</label>
                        <select name="limit" class="form-select">
                            <option value="25" <?= $limit === 25 ? 'selected' : '' ?>>25</option>
                            <option value="50" <?= $limit === 50 ? 'selected' : '' ?>>50</option>
                            <option value="100" <?= $limit === 100 ? 'selected' : '' ?>>100</option>
                        </select>
                    </div>
                    <div class="col-md-6 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="fas fa-filter me-1"></i> Filter
                        </button>
                        <a href="?donor_id=<?= $donorId ?>" class="btn btn-outline-secondary">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Message List -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Message History</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($messages)): ?>
                    <div class="p-4 text-center text-muted">
                        <i class="fas fa-inbox fa-3x mb-3"></i>
                        <p>No messages found for this donor.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Date & Time</th>
                                    <th>Channel</th>
                                    <th>Message</th>
                                    <th>Template</th>
                                    <th>Sent By</th>
                                    <th>Status</th>
                                    <th>Delivery</th>
                                    <th>Cost</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($messages as $message): ?>
                                    <tr>
                                        <td>
                                            <small>
                                                <?= date('d M Y H:i', strtotime($message['sent_at'])) ?>
                                            </small>
                                        </td>
                                        <td>
                                            <?php if ($message['channel'] === 'sms'): ?>
                                                <span class="badge bg-info channel-badge">
                                                    <i class="fas fa-sms me-1"></i>SMS
                                                </span>
                                            <?php elseif ($message['channel'] === 'whatsapp'): ?>
                                                <span class="badge bg-success channel-badge">
                                                    <i class="fab fa-whatsapp me-1"></i>WhatsApp
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary channel-badge">Both</span>
                                            <?php endif; ?>
                                            
                                            <?php if ($message['is_fallback'] ?? 0): ?>
                                                <br><small class="text-muted">(Fallback)</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="message-preview" title="<?= htmlspecialchars($message['message_content']) ?>">
                                                <?= htmlspecialchars(mb_substr($message['message_content'], 0, 50)) ?>
                                                <?= mb_strlen($message['message_content']) > 50 ? '...' : '' ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($message['template_key']): ?>
                                                <code><?= htmlspecialchars($message['template_key']) ?></code>
                                            <?php else: ?>
                                                <span class="text-muted">Direct</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($message['sent_by_name']): ?>
                                                <strong><?= htmlspecialchars($message['sent_by_name']) ?></strong>
                                                <br><small class="text-muted"><?= htmlspecialchars($message['sent_by_role'] ?? 'system') ?></small>
                                            <?php else: ?>
                                                <span class="text-muted">System</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            $status = $message['status'] ?? 'unknown';
                                            $badgeClass = match($status) {
                                                'sent' => 'bg-primary',
                                                'delivered' => 'bg-success',
                                                'read' => 'bg-success',
                                                'failed' => 'bg-danger',
                                                'pending' => 'bg-warning',
                                                default => 'bg-secondary'
                                            };
                                            ?>
                                            <span class="badge <?= $badgeClass ?> status-badge">
                                                <?= ucfirst($status) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($message['delivered_at']): ?>
                                                <small class="text-success">
                                                    <i class="fas fa-check-circle me-1"></i>
                                                    Delivered<br>
                                                    <?php if ($message['delivery_time_seconds']): ?>
                                                        <span class="text-muted">
                                                            <?= round($message['delivery_time_seconds'] / 60, 1) ?> min
                                                        </span>
                                                    <?php endif; ?>
                                                </small>
                                            <?php elseif ($message['read_at']): ?>
                                                <small class="text-success">
                                                    <i class="fas fa-eye me-1"></i>
                                                    Read<br>
                                                    <?php if ($message['read_time_seconds']): ?>
                                                        <span class="text-muted">
                                                            <?= round($message['read_time_seconds'] / 60, 1) ?> min
                                                        </span>
                                                    <?php endif; ?>
                                                </small>
                                            <?php elseif ($message['failed_at']): ?>
                                                <small class="text-danger">
                                                    <i class="fas fa-times-circle me-1"></i>
                                                    Failed
                                                </small>
                                            <?php else: ?>
                                                <small class="text-muted">Pending</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($message['cost_pence']): ?>
                                                £<?= number_format($message['cost_pence'] / 100, 2) ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>

