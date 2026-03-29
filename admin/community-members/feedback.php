<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_login();
require_admin();
require_once __DIR__ . '/../../config/db.php';

$page_title = 'Community Feedback';
$db = db();

// Create table if not exists
$db->query("
    CREATE TABLE IF NOT EXISTS community_feedback (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        contact VARCHAR(255) DEFAULT NULL,
        type ENUM('feedback','suggestion','complaint','praise') NOT NULL DEFAULT 'feedback',
        message TEXT NOT NULL,
        status ENUM('new','reviewed','resolved') NOT NULL DEFAULT 'new',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_type (type),
        INDEX idx_status (status),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_id'], $_POST['new_status'])) {
    $uid = (int) $_POST['update_id'];
    $newStatus = $_POST['new_status'];
    if (in_array($newStatus, ['new', 'reviewed', 'resolved'])) {
        $stmt = $db->prepare("UPDATE community_feedback SET status = ? WHERE id = ?");
        $stmt->bind_param('si', $newStatus, $uid);
        $stmt->execute();
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $delId = (int) $_POST['delete_id'];
    $stmt = $db->prepare("DELETE FROM community_feedback WHERE id = ?");
    $stmt->bind_param('i', $delId);
    $stmt->execute();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Fetch stats
$stats = $db->query("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) as new_count,
        SUM(CASE WHEN type = 'complaint' THEN 1 ELSE 0 END) as complaints,
        SUM(CASE WHEN type = 'suggestion' THEN 1 ELSE 0 END) as suggestions,
        SUM(CASE WHEN type = 'praise' THEN 1 ELSE 0 END) as praises
    FROM community_feedback
")->fetch_assoc();

// Fetch feedback
$items = $db->query("SELECT * FROM community_feedback ORDER BY created_at DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <?php include __DIR__ . '/../includes/pwa.php'; ?>
    <link rel="stylesheet" href="../assets/admin.css">
</head>
<body>
<div class="admin-wrapper">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="admin-content">
        <?php include __DIR__ . '/../includes/topbar.php'; ?>
        <main class="main-content">
            <div class="container-fluid">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h4 class="mb-0"><i class="fas fa-comments me-2"></i>Community Feedback</h4>
                    <a href="index.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-users me-1"></i>Members</a>
                </div>

                <!-- Stats -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-md-3">
                        <div class="card text-center p-3 border-0 shadow-sm">
                            <div class="fs-2 fw-bold text-primary"><?= $stats['total'] ?></div>
                            <div class="text-muted small">Total</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="card text-center p-3 border-0 shadow-sm">
                            <div class="fs-2 fw-bold text-danger"><?= $stats['new_count'] ?></div>
                            <div class="text-muted small">New / Unread</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="card text-center p-3 border-0 shadow-sm">
                            <div class="fs-2 fw-bold text-warning"><?= $stats['complaints'] ?></div>
                            <div class="text-muted small">Complaints</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="card text-center p-3 border-0 shadow-sm">
                            <div class="fs-2 fw-bold text-success"><?= $stats['praises'] ?></div>
                            <div class="text-muted small">Praise</div>
                        </div>
                    </div>
                </div>

                <!-- Feedback list -->
                <?php while ($f = $items->fetch_assoc()):
                    $typeBadge = [
                        'feedback'   => 'bg-primary',
                        'suggestion' => 'bg-info',
                        'complaint'  => 'bg-danger',
                        'praise'     => 'bg-success'
                    ];
                    $statusBadge = [
                        'new'      => 'bg-danger',
                        'reviewed' => 'bg-warning text-dark',
                        'resolved' => 'bg-success'
                    ];
                ?>
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <strong><?= htmlspecialchars($f['name']) ?></strong>
                                <?php if ($f['contact']): ?>
                                    <span class="text-muted small ms-2"><?= htmlspecialchars($f['contact']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex gap-2 align-items-center">
                                <span class="badge <?= $typeBadge[$f['type']] ?? 'bg-secondary' ?>"><?= ucfirst($f['type']) ?></span>
                                <span class="badge <?= $statusBadge[$f['status']] ?? 'bg-secondary' ?>"><?= ucfirst($f['status']) ?></span>
                            </div>
                        </div>
                        <p class="mb-2" style="white-space:pre-line;"><?= htmlspecialchars($f['message']) ?></p>
                        <div class="d-flex justify-content-between align-items-center">
                            <small class="text-muted"><?= date('d M Y, H:i', strtotime($f['created_at'])) ?></small>
                            <div class="d-flex gap-2">
                                <?php if ($f['status'] === 'new'): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="update_id" value="<?= $f['id'] ?>">
                                    <input type="hidden" name="new_status" value="reviewed">
                                    <button type="submit" class="btn btn-outline-warning btn-sm">Mark Reviewed</button>
                                </form>
                                <?php endif; ?>
                                <?php if ($f['status'] !== 'resolved'): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="update_id" value="<?= $f['id'] ?>">
                                    <input type="hidden" name="new_status" value="resolved">
                                    <button type="submit" class="btn btn-outline-success btn-sm">Resolve</button>
                                </form>
                                <?php endif; ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this feedback?')">
                                    <input type="hidden" name="delete_id" value="<?= $f['id'] ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm"><i class="fas fa-trash"></i></button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>

                <?php if ($stats['total'] == 0): ?>
                <div class="card border-0 shadow-sm">
                    <div class="card-body text-center text-muted py-5">No feedback received yet.</div>
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
