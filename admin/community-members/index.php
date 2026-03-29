<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_login();
require_admin();
require_once __DIR__ . '/../../config/db.php';

$page_title = 'Community Members';
$db = db();

// Create table if not exists
$db->query("
    CREATE TABLE IF NOT EXISTS community_members (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(255) NOT NULL,
        email VARCHAR(255) DEFAULT NULL,
        phone VARCHAR(50) DEFAULT NULL,
        preference ENUM('phone','email','both') NOT NULL DEFAULT 'phone',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_phone (phone),
        INDEX idx_email (email),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $delId = (int) $_POST['delete_id'];
    $stmt = $db->prepare("DELETE FROM community_members WHERE id = ?");
    $stmt->bind_param('i', $delId);
    $stmt->execute();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="community-members-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Full Name', 'Phone', 'Email', 'Preference', 'Registered']);
    $result = $db->query("SELECT * FROM community_members ORDER BY created_at DESC");
    while ($row = $result->fetch_assoc()) {
        fputcsv($out, [$row['id'], $row['full_name'], $row['phone'], $row['email'], $row['preference'], $row['created_at']]);
    }
    fclose($out);
    exit;
}

// Fetch stats
$stats = $db->query("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN preference = 'phone' THEN 1 ELSE 0 END) as phone_pref,
        SUM(CASE WHEN preference = 'email' THEN 1 ELSE 0 END) as email_pref,
        SUM(CASE WHEN preference = 'both' THEN 1 ELSE 0 END) as both_pref
    FROM community_members
")->fetch_assoc();

// Fetch members
$members = $db->query("SELECT * FROM community_members ORDER BY created_at DESC");
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
                    <h4 class="mb-0"><i class="fas fa-users me-2"></i>Community Members</h4>
                    <a href="?export=csv" class="btn btn-outline-success btn-sm"><i class="fas fa-download me-1"></i>Export CSV</a>
                </div>

                <!-- Stats -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-md-3">
                        <div class="card text-center p-3 border-0 shadow-sm">
                            <div class="fs-2 fw-bold text-primary"><?= $stats['total'] ?></div>
                            <div class="text-muted small">Total Members</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="card text-center p-3 border-0 shadow-sm">
                            <div class="fs-2 fw-bold text-success"><?= $stats['phone_pref'] ?></div>
                            <div class="text-muted small">Phone Only</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="card text-center p-3 border-0 shadow-sm">
                            <div class="fs-2 fw-bold text-info"><?= $stats['email_pref'] ?></div>
                            <div class="text-muted small">Email Only</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="card text-center p-3 border-0 shadow-sm">
                            <div class="fs-2 fw-bold text-warning"><?= $stats['both_pref'] ?></div>
                            <div class="text-muted small">Both</div>
                        </div>
                    </div>
                </div>

                <!-- Table -->
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Full Name</th>
                                        <th>Phone</th>
                                        <th>Email</th>
                                        <th>Preference</th>
                                        <th>Registered</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $i = 0; while ($m = $members->fetch_assoc()): $i++; ?>
                                    <tr>
                                        <td><?= $i ?></td>
                                        <td class="fw-semibold"><?= htmlspecialchars($m['full_name']) ?></td>
                                        <td><?= $m['phone'] ? htmlspecialchars($m['phone']) : '<span class="text-muted">—</span>' ?></td>
                                        <td><?= $m['email'] ? htmlspecialchars($m['email']) : '<span class="text-muted">—</span>' ?></td>
                                        <td>
                                            <?php
                                            $badgeMap = [
                                                'phone' => 'bg-success',
                                                'email' => 'bg-info',
                                                'both'  => 'bg-warning text-dark'
                                            ];
                                            $badge = $badgeMap[$m['preference']] ?? 'bg-secondary';
                                            ?>
                                            <span class="badge <?= $badge ?>"><?= ucfirst($m['preference']) ?></span>
                                        </td>
                                        <td class="text-muted small"><?= date('d M Y, H:i', strtotime($m['created_at'])) ?></td>
                                        <td>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Delete this member?')">
                                                <input type="hidden" name="delete_id" value="<?= $m['id'] ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                    <?php if ($i === 0): ?>
                                    <tr><td colspan="7" class="text-center text-muted py-4">No community members registered yet.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
</body>
</html>
