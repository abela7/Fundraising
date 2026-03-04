<?php
declare(strict_types=1);
/**
 * Deleted Pledges History
 * View records from the deleted_pledges table (audit trail of deleted pledges).
 */
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

require_once __DIR__ . '/../includes/resilient_db_loader.php';

$db = db();
$page_title = 'Deleted Pledges History';

// Check if table exists
$table_check = $db->query("SHOW TABLES LIKE 'deleted_pledges'");
$table_exists = ($table_check && $table_check->num_rows > 0);

$records = [];
$total_count = 0;
$total_amount = 0;
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;
$search = trim((string)($_GET['q'] ?? ''));

if ($table_exists) {
    $where = [];
    $params = [];
    $types = '';
    if ($search !== '' && strlen($search) >= 2) {
        $where[] = "(dp.donor_name LIKE ? OR dp.pledge_id = ?)";
        $params[] = '%' . $search . '%';
        $params[] = is_numeric($search) ? (int)$search : 0;
        $types .= 'si';
    }
    $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $count_sql = "SELECT COUNT(*) as c, COALESCE(SUM(amount), 0) as total FROM deleted_pledges dp $where_sql";
    $cnt_stmt = $db->prepare($count_sql);
    if ($types !== '') {
        $cnt_stmt->bind_param($types, ...$params);
    }
    $cnt_stmt->execute();
    $cnt_row = $cnt_stmt->get_result()->fetch_assoc();
    $total_count = (int)($cnt_row['c'] ?? 0);
    $total_amount = (float)($cnt_row['total'] ?? 0);
    $cnt_stmt->close();

    $offset = ($page - 1) * $per_page;
    $total_pages = (int)ceil($total_count / $per_page);

    $sql = "SELECT dp.*, u.name as deleted_by_name
            FROM deleted_pledges dp
            LEFT JOIN users u ON u.id = dp.deleted_by
            $where_sql
            ORDER BY dp.deleted_at DESC
            LIMIT ? OFFSET ?";
    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types . 'ii', ...array_merge($params, [$per_page, $offset]));
    } else {
        $stmt->bind_param('ii', $per_page, $offset);
    }
    $stmt->execute();
    $records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($page_title); ?> - Fundraising Admin</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <link rel="stylesheet" href="assets/donor-management.css">
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        <main class="main-content">
            <div class="container-fluid">
                <?php include '../includes/db_error_banner.php'; ?>

                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 mb-1 text-primary">
                            <i class="fas fa-trash-alt me-2"></i><?php echo htmlspecialchars($page_title); ?>
                        </h1>
                        <p class="text-muted mb-0">History of deleted pledges (donor name, amount, deletion date). Full audit trail in <a href="../audit/?action=delete&q=pledge">Audit Logs</a>.</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="../audit/?action=delete&q=pledge" class="btn btn-outline-primary">
                            <i class="fas fa-history me-2"></i>View in Audit Logs
                        </a>
                        <a href="index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Donor Management
                        </a>
                    </div>
                </div>

                <?php if (!$table_exists): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>No deleted pledges recorded yet. The history table is created when the first pledge is deleted.
                </div>
                <?php else: ?>

                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <form method="get" class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label visually-hidden">Search</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                                    <input type="text" name="q" class="form-control" placeholder="Search by donor name or pledge ID..." value="<?php echo htmlspecialchars($search); ?>">
                                    <button type="submit" class="btn btn-primary">Search</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-6">
                        <div class="card border-0 bg-light">
                            <div class="card-body py-3">
                                <span class="text-muted">Total deleted:</span>
                                <strong class="ms-2"><?php echo number_format($total_count); ?></strong> pledge(s)
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card border-0 bg-light">
                            <div class="card-body py-3">
                                <span class="text-muted">Total amount:</span>
                                <strong class="ms-2 text-danger">£<?php echo number_format($total_amount, 2); ?></strong>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Pledge ID</th>
                                    <th>Donor Name</th>
                                    <th>Amount</th>
                                    <th>Status at Deletion</th>
                                    <th>Deleted At</th>
                                    <th>Deleted By</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($records)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-5 text-muted">
                                        <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                                        No deleted pledges found.
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($records as $r): ?>
                                <tr>
                                    <td><code>#<?php echo (int)($r['pledge_id'] ?? 0); ?></code></td>
                                    <td><?php echo htmlspecialchars($r['donor_name'] ?? '-'); ?></td>
                                    <td class="fw-bold">£<?php echo number_format((float)($r['amount'] ?? 0), 2); ?></td>
                                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars(ucfirst($r['status'] ?? '-')); ?></span></td>
                                    <td><?php echo $r['deleted_at'] ? date('M d, Y H:i', strtotime($r['deleted_at'])) : '-'; ?></td>
                                    <td><?php echo htmlspecialchars($r['deleted_by_name'] ?? 'System'); ?></td>
                                    <td>
                                        <a href="view-donor.php?id=<?php echo (int)($r['donor_id'] ?? 0); ?>" class="btn btn-sm btn-outline-primary" title="View donor">
                                            <i class="fas fa-user"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($total_pages > 1): ?>
                    <div class="card-footer d-flex justify-content-between align-items-center">
                        <span class="text-muted small">Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
                        <nav>
                            <ul class="pagination pagination-sm mb-0">
                                <?php if ($page > 1): ?>
                                <li class="page-item"><a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo $search ? '&q=' . urlencode($search) : ''; ?>">Previous</a></li>
                                <?php endif; ?>
                                <?php if ($page < $total_pages): ?>
                                <li class="page-item"><a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo $search ? '&q=' . urlencode($search) : ''; ?>">Next</a></li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="alert alert-light border mt-4">
                    <h6 class="mb-2"><i class="fas fa-info-circle me-2 text-primary"></i>Full audit trail</h6>
                    <p class="mb-0 small">Each deletion is also logged in <strong>Audit Logs</strong> with before/after JSON (cells deallocated, payments unlinked, etc.). Go to <a href="../audit/">Audit Logs</a> → filter by <strong>Action: Delete</strong> and search <strong>pledge</strong>.</p>
                </div>

                <?php endif; ?>
            </div>
        </main>
    </div>
</div>
</body>
</html>
