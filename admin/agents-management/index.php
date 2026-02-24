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

// Summary stats
$total_agents = count($agents);
$active_agents = 0;
$total_portfolio = 0;
$total_calls_sum = 0;
foreach ($agents as $a) {
    if ((int)($a['active'] ?? 0) === 1) $active_agents++;
    $total_portfolio += (int)($a['portfolio_count'] ?? 0);
    $total_calls_sum += (int)($a['total_calls'] ?? 0);
}

// Count active filters
$active_filter_count = 0;
if ($search !== '') $active_filter_count++;
if ($role_filter !== 'all') $active_filter_count++;
if ($status_filter !== 'all') $active_filter_count++;

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
        /* === Page Header === */
        .am-page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 20px;
        }

        .am-page-header h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-900);
            margin: 0;
        }

        .am-page-header p {
            color: var(--gray-500);
            font-size: 0.875rem;
            margin: 4px 0 0;
        }

        .am-admin-badge {
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            color: var(--white);
            padding: 6px 14px;
            border-radius: 8px;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.3px;
            text-transform: uppercase;
        }

        /* === Summary Stats Row === */
        .am-stats-row {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .am-stat-chip {
            display: flex;
            align-items: center;
            gap: 10px;
            background: var(--white);
            border: 1px solid var(--gray-200);
            border-radius: 10px;
            padding: 12px 18px;
            box-shadow: var(--shadow-sm);
            flex: 1;
            min-width: 150px;
        }

        .am-stat-chip:hover {
            box-shadow: var(--shadow-md);
        }

        .am-stat-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            flex-shrink: 0;
        }

        .am-stat-icon.primary { background: rgba(10, 98, 134, 0.1); color: var(--primary); }
        .am-stat-icon.success { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        .am-stat-icon.accent  { background: rgba(226, 202, 24, 0.12); color: var(--accent-dark); }
        .am-stat-icon.info    { background: rgba(59, 130, 246, 0.1); color: var(--info); }

        .am-stat-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--gray-900);
            line-height: 1.2;
        }

        .am-stat-label {
            font-size: 0.6875rem;
            font-weight: 500;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        /* === Filter Bar === */
        .am-filter-bar {
            background: var(--white);
            border: 1px solid var(--gray-200);
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-sm);
        }

        .am-filter-bar .form-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 4px;
        }

        .am-filter-bar .form-control,
        .am-filter-bar .form-select {
            border: 1px solid var(--gray-200);
            border-radius: 8px;
            font-size: 0.875rem;
            padding: 8px 12px;
        }

        .am-filter-bar .form-control:focus,
        .am-filter-bar .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(10, 98, 134, 0.1);
        }

        .am-filter-actions .btn {
            border-radius: 8px;
            font-weight: 500;
            font-size: 0.875rem;
            padding: 8px 16px;
        }

        /* === Agents Grid === */
        .am-agent-card {
            background: var(--white);
            border: 1px solid var(--gray-200);
            border-radius: 12px;
            padding: 20px;
            box-shadow: var(--shadow-sm);
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .am-agent-card:hover {
            box-shadow: var(--shadow-md);
            border-color: var(--gray-300);
        }

        .am-agent-top {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 16px;
        }

        .am-agent-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.125rem;
            flex-shrink: 0;
        }

        .am-agent-info {
            flex: 1;
            min-width: 0;
        }

        .am-agent-name {
            font-weight: 600;
            font-size: 1rem;
            color: var(--gray-900);
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .am-agent-contact {
            font-size: 0.8125rem;
            color: var(--gray-500);
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .am-agent-contact i {
            width: 14px;
            text-align: center;
            margin-right: 4px;
            font-size: 0.7rem;
        }

        .am-agent-badges {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-bottom: 14px;
        }

        .am-badge {
            padding: 3px 10px;
            border-radius: 6px;
            font-size: 0.6875rem;
            font-weight: 600;
            letter-spacing: 0.3px;
            text-transform: uppercase;
        }

        .am-badge-admin {
            background: rgba(10, 98, 134, 0.1);
            color: var(--primary);
        }

        .am-badge-registrar {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }

        .am-badge-active {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .am-badge-inactive {
            background: rgba(107, 114, 128, 0.1);
            color: var(--gray-500);
        }

        .am-badge-id {
            background: var(--gray-100);
            color: var(--gray-600);
        }

        /* Agent Metrics */
        .am-agent-metrics {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-bottom: 16px;
            flex: 1;
        }

        .am-metric {
            background: var(--gray-50);
            border-radius: 8px;
            padding: 10px 12px;
            text-align: center;
        }

        .am-metric-value {
            font-size: 1rem;
            font-weight: 700;
            color: var(--gray-800);
        }

        .am-metric-label {
            font-size: 0.6875rem;
            color: var(--gray-500);
            margin-top: 2px;
        }

        /* Agent Footer */
        .am-agent-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            padding-top: 14px;
            border-top: 1px solid var(--gray-100);
        }

        .am-agent-footer .am-login-time {
            font-size: 0.75rem;
            color: var(--gray-400);
        }

        .am-agent-footer .am-login-time i {
            margin-right: 4px;
        }

        .am-view-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border: 1px solid var(--primary);
            color: var(--primary);
            border-radius: 8px;
            font-size: 0.8125rem;
            font-weight: 500;
            text-decoration: none;
            background: transparent;
        }

        .am-view-btn:hover {
            background: var(--primary);
            color: var(--white);
            box-shadow: 0 2px 8px rgba(10, 98, 134, 0.25);
        }

        /* Empty State */
        .am-empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray-500);
        }

        .am-empty-state i {
            font-size: 3rem;
            color: var(--gray-300);
            margin-bottom: 12px;
        }

        .am-empty-state p {
            font-size: 0.9375rem;
        }

        /* Results count */
        .am-results-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .am-results-count {
            font-size: 0.8125rem;
            color: var(--gray-500);
            font-weight: 500;
        }

        .am-results-count strong {
            color: var(--gray-800);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .am-stats-row {
                flex-direction: column;
            }

            .am-stat-chip {
                min-width: auto;
            }

            .am-page-header {
                flex-direction: column;
            }

            .am-agent-metrics {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 576px) {
            .am-filter-bar {
                padding: 12px 16px;
            }

            .am-agent-card {
                padding: 16px;
            }
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

            <!-- Page Header -->
            <div class="am-page-header">
                <div>
                    <h1><i class="fas fa-user-tie me-2" style="color: var(--primary);"></i>Agents Management</h1>
                    <p>Monitor agent activity, donor portfolios, and call performance</p>
                </div>
                <span class="am-admin-badge"><i class="fas fa-shield-halved me-1"></i>Admin Only</span>
            </div>

            <!-- Summary Stats -->
            <div class="am-stats-row">
                <div class="am-stat-chip">
                    <div class="am-stat-icon primary"><i class="fas fa-users"></i></div>
                    <div>
                        <div class="am-stat-value"><?php echo $total_agents; ?></div>
                        <div class="am-stat-label"><?php echo $active_filter_count > 0 ? 'Matching' : 'Total'; ?> Agents</div>
                    </div>
                </div>
                <div class="am-stat-chip">
                    <div class="am-stat-icon success"><i class="fas fa-circle-check"></i></div>
                    <div>
                        <div class="am-stat-value"><?php echo $active_agents; ?></div>
                        <div class="am-stat-label">Active</div>
                    </div>
                </div>
                <div class="am-stat-chip">
                    <div class="am-stat-icon accent"><i class="fas fa-address-book"></i></div>
                    <div>
                        <div class="am-stat-value"><?php echo number_format($total_portfolio); ?></div>
                        <div class="am-stat-label">Total Donors</div>
                    </div>
                </div>
                <div class="am-stat-chip">
                    <div class="am-stat-icon info"><i class="fas fa-phone"></i></div>
                    <div>
                        <div class="am-stat-value"><?php echo number_format($total_calls_sum); ?></div>
                        <div class="am-stat-label">Total Calls</div>
                    </div>
                </div>
            </div>

            <!-- Filter Bar -->
            <form method="get" class="am-filter-bar" id="filterForm">
                <div class="row g-2 align-items-end">
                    <div class="col-12 col-md-4">
                        <label class="form-label"><i class="fas fa-search me-1"></i>Search</label>
                        <input class="form-control" type="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Name, phone, email, or ID">
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
                        <label class="form-label">Sort By</label>
                        <select name="sort" class="form-select">
                            <option value="name" <?php echo $sort === 'name' ? 'selected' : ''; ?>>Name</option>
                            <option value="created" <?php echo $sort === 'created' ? 'selected' : ''; ?>>Created Date</option>
                            <option value="last_login" <?php echo $sort === 'last_login' ? 'selected' : ''; ?>>Last Login</option>
                            <option value="portfolio" <?php echo $sort === 'portfolio' ? 'selected' : ''; ?>>Portfolio Size</option>
                            <option value="calls" <?php echo $sort === 'calls' ? 'selected' : ''; ?>>Call Count</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-1">
                        <label class="form-label">Order</label>
                        <select name="order" class="form-select">
                            <option value="asc" <?php echo $order === 'asc' ? 'selected' : ''; ?>>Asc</option>
                            <option value="desc" <?php echo $order === 'desc' ? 'selected' : ''; ?>>Desc</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-1 am-filter-actions d-flex gap-2">
                        <button class="btn btn-primary flex-fill" type="submit">
                            <i class="fas fa-search"></i>
                        </button>
                        <a class="btn btn-outline-secondary" href="index.php" title="Reset">
                            <i class="fas fa-times"></i>
                        </a>
                    </div>
                </div>
            </form>

            <!-- Results Bar -->
            <div class="am-results-bar">
                <div class="am-results-count">
                    Showing <strong><?php echo $total_agents; ?></strong> agent<?php echo $total_agents !== 1 ? 's' : ''; ?>
                    <?php if ($active_filter_count > 0): ?>
                        <span class="badge bg-primary ms-1" style="font-size: 0.6875rem;"><?php echo $active_filter_count; ?> filter<?php echo $active_filter_count > 1 ? 's' : ''; ?> active</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Agents Grid -->
            <?php if (!$agents): ?>
                <div class="am-empty-state">
                    <i class="fas fa-user-slash d-block"></i>
                    <p>No agents found for the selected filters.</p>
                    <a href="index.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-times me-1"></i>Clear Filters</a>
                </div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($agents as $agent): ?>
                        <?php
                        $is_active = (int)($agent['active'] ?? 0) === 1;
                        $calls_total = (int)($agent['total_calls'] ?? 0);
                        $calls_success = (int)($agent['successful_calls'] ?? 0);
                        $success_rate = $calls_total > 0 ? round(($calls_success / $calls_total) * 100) : 0;
                        $portfolio = (int)($agent['portfolio_count'] ?? 0);
                        $initials = strtoupper(substr((string)$agent['name'], 0, 1));
                        ?>
                        <div class="col-12 col-md-6 col-xl-4">
                            <div class="am-agent-card">
                                <div class="am-agent-top">
                                    <div class="am-agent-avatar"><?php echo $initials; ?></div>
                                    <div class="am-agent-info">
                                        <div class="am-agent-name"><?php echo htmlspecialchars((string)$agent['name']); ?></div>
                                        <div class="am-agent-contact">
                                            <?php if (!empty($agent['phone'])): ?>
                                                <span><i class="fas fa-phone"></i><?php echo htmlspecialchars((string)$agent['phone']); ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($agent['email'])): ?>
                                                <span><i class="fas fa-envelope"></i><?php echo htmlspecialchars((string)$agent['email']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="am-agent-badges">
                                    <span class="am-badge <?php echo $agent['role'] === 'admin' ? 'am-badge-admin' : 'am-badge-registrar'; ?>">
                                        <?php echo htmlspecialchars((string)$agent['role']); ?>
                                    </span>
                                    <span class="am-badge <?php echo $is_active ? 'am-badge-active' : 'am-badge-inactive'; ?>">
                                        <?php echo $is_active ? 'Active' : 'Inactive'; ?>
                                    </span>
                                    <span class="am-badge am-badge-id">#<?php echo (int)$agent['id']; ?></span>
                                </div>

                                <div class="am-agent-metrics">
                                    <div class="am-metric">
                                        <div class="am-metric-value"><?php echo $portfolio; ?></div>
                                        <div class="am-metric-label">Donors</div>
                                    </div>
                                    <div class="am-metric">
                                        <div class="am-metric-value"><?php echo $calls_total; ?></div>
                                        <div class="am-metric-label">Calls</div>
                                    </div>
                                    <div class="am-metric">
                                        <div class="am-metric-value"><?php echo $calls_success; ?></div>
                                        <div class="am-metric-label">Engaged</div>
                                    </div>
                                    <div class="am-metric">
                                        <div class="am-metric-value"><?php echo $success_rate; ?>%</div>
                                        <div class="am-metric-label">Success</div>
                                    </div>
                                </div>

                                <div class="am-agent-footer">
                                    <span class="am-login-time">
                                        <i class="fas fa-clock"></i>
                                        <?php if (!empty($agent['last_login_at'])): ?>
                                            <?php echo date('M j, H:i', strtotime((string)$agent['last_login_at'])); ?>
                                        <?php else: ?>
                                            Never logged in
                                        <?php endif; ?>
                                    </span>
                                    <a href="view-agent.php?id=<?php echo (int)$agent['id']; ?>" class="am-view-btn">
                                        <i class="fas fa-chart-line"></i>Profile
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
</body>
</html>
