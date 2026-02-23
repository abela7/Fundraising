<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

require_admin();

$role_filter = strtolower(trim((string)($_GET['role'] ?? 'all')));
if (!in_array($role_filter, ['all', 'admin', 'registrar'], true)) {
    $role_filter = 'all';
}

$status_filter = strtolower(trim((string)($_GET['status'] ?? 'all')));
if (!in_array($status_filter, ['all', 'active', 'inactive'], true)) {
    $status_filter = 'all';
}

$search = trim((string)($_GET['search'] ?? ''));
$sort = strtolower(trim((string)($_GET['sort'] ?? 'name')));
$order = strtolower(trim((string)($_GET['order'] ?? 'asc')));

$valid_sort = ['name', 'created', 'last_login', 'portfolio', 'calls'];
if (!in_array($sort, $valid_sort, true)) {
    $sort = 'name';
}

if (!in_array($order, ['asc', 'desc'], true)) {
    $order = 'asc';
}

$sort_map = [
    'name' => 'u.name',
    'created' => 'u.created_at',
    'last_login' => 'u.last_login_at',
    'portfolio' => 'portfolio_count',
    'calls' => 'total_calls',
];

$db = db();
$agents = [];
$error = '';

try {
    $where = ["u.role IN ('admin', 'registrar')"];
    $params = [];
    $types = '';

    if ($role_filter !== 'all') {
        $where[] = 'u.role = ?';
        $params[] = $role_filter;
        $types .= 's';
    }

    if ($status_filter === 'active') {
        $where[] = 'u.active = 1';
    } elseif ($status_filter === 'inactive') {
        $where[] = 'u.active = 0';
    }

    if ($search !== '') {
        $where[] = '(u.name LIKE ? OR u.phone LIKE ? OR u.email LIKE ? OR CAST(u.id AS CHAR) LIKE ?)';
        $search_like = '%' . $search . '%';
        $params[] = $search_like;
        $params[] = $search_like;
        $params[] = $search_like;
        $params[] = $search_like;
        $types .= 'ssss';
    }

    $where_sql = ' WHERE ' . implode(' AND ', $where);

    $sql = "
        SELECT
            u.id,
            u.name,
            u.phone,
            u.phone_number,
            u.email,
            u.role,
            u.active,
            u.last_login_at,
            u.created_at,
            (
                SELECT COUNT(DISTINCT d.id)
                FROM donors d
                WHERE d.agent_id = u.id OR d.registered_by_user_id = u.id
            ) AS portfolio_count,
            (
                SELECT COUNT(*)
                FROM call_center_sessions cs
                WHERE cs.agent_id = u.id
            ) AS total_calls,
            (
                SELECT COUNT(*)
                FROM call_center_sessions cs
                WHERE cs.agent_id = u.id
                  AND (
                    cs.conversation_stage IN ('contact_made', 'interested_follow_up', 'success_pledged', 'callback_scheduled')
                    OR cs.outcome IN ('payment_plan_created', 'agreed_to_pay_full', 'agreed_reduced_amount', 'payment_made_during_call')
                  )
            ) AS successful_calls,
            (
                SELECT MAX(cs.call_started_at)
                FROM call_center_sessions cs
                WHERE cs.agent_id = u.id
            ) AS last_call_at
        FROM users u
        {$where_sql}
        ORDER BY {$sort_map[$sort]} {$order}
    ";

    $stmt = $db->prepare($sql);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $agents = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Exception $e) {
    $error = $e->getMessage();
}

$page_title = 'Agents Management';
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
    <style>
        .agent-management-table .agent-meta {
            min-width: 240px;
        }
        .agent-management-search {
            min-width: 240px;
        }
        .metric-pill {
            font-size: 0.75rem;
            padding: 0.3rem 0.55rem;
            border-radius: 999px;
        }
        .table thead th {
            white-space: nowrap;
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="admin-content">
        <?php include __DIR__ . '/../includes/topbar.php'; ?>

        <main class="main-content">
            <?php if ($error): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Unable to load agents: <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                <div>
                    <h1 class="h3 mb-1">Agents Management</h1>
                    <p class="text-muted mb-0">Monitor agents and their assigned or managed donor portfolios.</p>
                </div>
                <div>
                    <span class="badge text-bg-primary">Super Admin View</span>
                </div>
            </div>

            <form method="get" class="row g-2 mb-3 align-items-end">
                <div class="col-12 col-md-4">
                    <label class="form-label">Search</label>
                    <input class="form-control agent-management-search" type="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Name, phone, email, or ID">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label">Role</label>
                    <select name="role" class="form-select">
                        <option value="all" <?php echo $role_filter === 'all' ? 'selected' : ''; ?>>All Roles</option>
                        <option value="admin" <?php echo $role_filter === 'admin' ? 'selected' : ''; ?>>Admin</option>
                        <option value="registrar" <?php echo $role_filter === 'registrar' ? 'selected' : ''; ?>>Registrar</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All</option>
                        <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label">Sort</label>
                    <select name="sort" class="form-select">
                        <option value="name" <?php echo $sort === 'name' ? 'selected' : ''; ?>>Name</option>
                        <option value="created" <?php echo $sort === 'created' ? 'selected' : ''; ?>>Created Date</option>
                        <option value="last_login" <?php echo $sort === 'last_login' ? 'selected' : ''; ?>>Last Login</option>
                        <option value="portfolio" <?php echo $sort === 'portfolio' ? 'selected' : ''; ?>>Donor Portfolio</option>
                        <option value="calls" <?php echo $sort === 'calls' ? 'selected' : ''; ?>>Calls</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label">Order</label>
                    <select name="order" class="form-select">
                        <option value="asc" <?php echo $order === 'asc' ? 'selected' : ''; ?>>Asc</option>
                        <option value="desc" <?php echo $order === 'desc' ? 'selected' : ''; ?>>Desc</option>
                    </select>
                </div>
                <div class="col-12 col-md-2 d-grid gap-2">
                    <button class="btn btn-primary" type="submit">
                        <i class="fas fa-search me-1"></i>Apply
                    </button>
                    <a class="btn btn-outline-secondary" href="index.php">Reset</a>
                </div>
            </form>

            <div class="card">
                <div class="card-body table-responsive p-0">
                    <table class="table table-hover mb-0 align-middle agent-management-table">
                        <thead class="table-light">
                        <tr>
                            <th>Agent</th>
                            <th>Role</th>
                            <th>Portfolio</th>
                            <th>Performance</th>
                            <th>Last Login</th>
                            <th>Last Call</th>
                            <th>Action</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($agents as $agent): ?>
                            <tr>
                                <td>
                                    <div class="agent-meta">
                                        <div class="fw-semibold"><?php echo htmlspecialchars((string)$agent['name']); ?></div>
                                        <div class="small text-muted mt-1">
                                            <div><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars((string)($agent['phone'] ?? '')); ?></div>
                                            <div><i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars((string)($agent['email'] ?? '')); ?></div>
                                            <div class="mt-1">
                                                <span class="badge bg-<?php echo ((int)($agent['active'] ?? 0) === 1 ? 'success' : 'secondary'); ?> metric-pill"><?php echo ((int)($agent['active'] ?? 0) === 1 ? 'Active' : 'Inactive'); ?></span>
                                                <span class="badge bg-dark metric-pill ms-1">#<?php echo (int)$agent['id']; ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo ($agent['role'] === 'admin' ? 'primary' : 'info'); ?> text-uppercase">
                                        <?php echo htmlspecialchars((string)$agent['role']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div>
                                        <span class="badge bg-primary-subtle text-primary metric-pill"><?php echo (int)($agent['portfolio_count'] ?? 0); ?> Donors</span>
                                    </div>
                                    <div class="small text-muted mt-1">Assigned + registered</div>
                                </td>
                                <td>
                                    <div>
                                        <span class="badge bg-success-subtle text-success metric-pill me-1"><?php echo (int)($agent['total_calls'] ?? 0); ?> Calls</span>
                                        <span class="badge bg-warning-subtle text-warning metric-pill"><?php echo (int)($agent['successful_calls'] ?? 0); ?> Engaged</span>
                                    </div>
                                    <div class="small text-muted mt-1">
                                        <?php if (!empty($agent['last_call_at'])): ?>
                                            Last: <?php echo date('M j, Y H:i', strtotime((string)$agent['last_call_at'])); ?>
                                        <?php else: ?>
                                            No calls yet
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if (!empty($agent['last_login_at'])): ?>
                                        <span class="small"><?php echo date('M j, Y H:i', strtotime((string)$agent['last_login_at'])); ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary-subtle text-secondary">Never</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($agent['last_call_at'])): ?>
                                        <span class="small"><?php echo date('M j, Y H:i', strtotime((string)$agent['last_call_at'])); ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary-subtle text-secondary">Not Called</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a class="btn btn-sm btn-outline-primary" href="view-agent.php?id=<?php echo (int)$agent['id']; ?>">
                                        <i class="fas fa-chart-line me-1"></i>View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (!$agents): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">No agents found for selected filters.</td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
