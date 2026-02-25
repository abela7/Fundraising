<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';

require_admin();

$page_title = 'Public Donation Requests';
$current_user = current_user();

$actionMsg = '';
$isError = false;

$db = null;
$dbConnected = false;
try {
    $db = db();
    $dbConnected = true;
} catch (Throwable $e) {
    $isError = true;
    $actionMsg = 'Unable to connect to the database.';
    error_log('Admin Public Donations - DB connection failed: ' . $e->getMessage());
}

function bind_query_params(mysqli_stmt $stmt, string $types, array $params): void {
    if (empty($params)) {
        return;
    }

    $bind = [$types];
    foreach ($params as $key => $value) {
        $bind[] = &$params[$key];
    }

    call_user_func_array([$stmt, 'bind_param'], $bind);
}

$normalizeRequestPhone = static function (string $rawPhone): string {
    $clean = preg_replace('/\D/', '', trim($rawPhone));
    return $clean === null ? '' : $clean;
};

$buildPhoneLookupList = static function (string $normalizedPhone): array {
    $clean = preg_replace('/\D/', '', $normalizedPhone) ?: '';
    if ($clean === '') {
        return [];
    }

    $candidates = [$clean];
    if (strlen($clean) > 2 && substr($clean, 0, 2) === '44') {
        $candidates[] = '0' . substr($clean, 2);
    }
    if (strlen($clean) > 1 && substr($clean, 0, 1) === '0') {
        $candidates[] = '44' . substr($clean, 1);
    }

    return array_values(array_unique($candidates));
};

$allowedStatuses = ['new', 'contacted', 'resolved', 'spam'];
$statusLabels = [
    'new' => ['label' => 'New', 'class' => 'bg-primary'],
    'contacted' => ['label' => 'Contacted', 'class' => 'bg-info text-dark'],
    'resolved' => ['label' => 'Resolved', 'class' => 'bg-success'],
    'spam' => ['label' => 'Spam', 'class' => 'bg-secondary'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $dbConnected && $db instanceof mysqli) {
    try {
        verify_csrf();

        $action = (string)($_POST['action'] ?? '');
        if ($action === 'update_status') {
            $requestId = (int)($_POST['request_id'] ?? 0);
            $status = (string)($_POST['status'] ?? '');

            if ($requestId <= 0 || !in_array($status, $allowedStatuses, true)) {
                $isError = true;
                $actionMsg = 'Please provide a valid request and status.';
            } else {
                $stmt = $db->prepare('UPDATE public_donation_requests SET status = ?, updated_by_user_id = ?, updated_at = NOW() WHERE id = ?');
                if ($stmt) {
                    $adminId = (int)($current_user['id'] ?? 0);
                    $stmt->bind_param('sii', $status, $adminId, $requestId);
                    $stmt->execute();

                    if ($stmt->affected_rows >= 0) {
                        $actionMsg = 'Status updated successfully.';
                    } else {
                        $isError = true;
                        $actionMsg = 'Unable to update status right now. Please retry.';
                    }
                    $stmt->close();

                    if (!$isError) {
                        $returnParams = [];
                        $returnKeys = ['search', 'filter_status', 'date_from', 'date_to', 'sort_by', 'sort_order', 'page', 'per_page'];
                        foreach ($returnKeys as $returnKey) {
                            if (!empty($_POST[$returnKey])) {
                                $returnParams[$returnKey] = (string)$_POST[$returnKey];
                            }
                        }
                        $returnParams['msg'] = (string)$actionMsg;
                        header('Location: public-donations.php' . ($returnParams ? ('?' . http_build_query($returnParams)) : ''));
                        exit;
                    }
                } else {
                    $isError = true;
                    $actionMsg = 'Database error while updating status.';
                }
            }
        }
    } catch (Throwable $e) {
        $isError = true;
        $actionMsg = 'Unable to update status right now.';
        error_log('Admin Public Donations - POST update failed: ' . $e->getMessage());
    }
}

$requestsTableExists = false;
if ($dbConnected && $db instanceof mysqli) {
    try {
        $tableCheck = $db->query("SHOW TABLES LIKE 'public_donation_requests'");
        $requestsTableExists = (bool)($tableCheck && $tableCheck->num_rows > 0);
    } catch (Throwable $e) {
        $isError = true;
        $actionMsg = 'Unable to verify the request table status.';
        error_log('Admin Public Donations - Failed table check: ' . $e->getMessage());
        $requestsTableExists = false;
    }
}

$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? $_GET['filter_status'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$sortBy = trim((string)($_GET['sort_by'] ?? 'created_at'));
$sortOrder = strtolower((string)($_GET['sort_order'] ?? 'desc'));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 20);
if (!in_array($perPage, [10, 20, 50], true)) {
    $perPage = 20;
}

$sortOptions = ['created_at', 'updated_at', 'full_name', 'phone_number', 'status'];
if (!in_array($sortBy, $sortOptions, true)) {
    $sortBy = 'created_at';
}
if ($sortOrder !== 'asc' && $sortOrder !== 'desc') {
    $sortOrder = 'desc';
}

$filters = ['1 = 1'];
$values = [];
$types = '';

if ($statusFilter !== '' && in_array($statusFilter, $allowedStatuses, true)) {
    $filters[] = 'status = ?';
    $values[] = $statusFilter;
    $types .= 's';
}

if ($search !== '') {
    $filters[] = '(full_name LIKE ? OR phone_number LIKE ? OR COALESCE(message, "") LIKE ? OR COALESCE(source_page, "") LIKE ? OR COALESCE(source_url, "") LIKE ? OR COALESCE(referrer_url, "") LIKE ?)';
    $pattern = '%' . $search . '%';
    $values = array_merge($values, [$pattern, $pattern, $pattern, $pattern, $pattern, $pattern]);
    $types .= 'ssssss';
}

$validatedDateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) ? $dateFrom : '';
$validatedDateTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) ? $dateTo : '';

if ($validatedDateFrom !== '') {
    $filters[] = 'DATE(created_at) >= ?';
    $values[] = $validatedDateFrom;
    $types .= 's';
}
if ($validatedDateTo !== '') {
    $filters[] = 'DATE(created_at) <= ?';
    $values[] = $validatedDateTo;
    $types .= 's';
}

$whereClause = implode(' AND ', $filters);

$totalItems = 0;
$totalPages = 0;
$requestRows = [];

if ($requestsTableExists && $dbConnected && $db instanceof mysqli) {
    try {
        $countSql = "SELECT COUNT(*) AS total_items FROM public_donation_requests WHERE {$whereClause}";
        $countStmt = $db->prepare($countSql);
        if (!$countStmt) {
            $isError = true;
            $actionMsg = 'Failed to build request query.';
        } else {
            bind_query_params($countStmt, $types, $values);
            $countStmt->execute();
            $countResult = $countStmt->get_result()->fetch_assoc();
            $totalItems = (int)($countResult['total_items'] ?? 0);
            $countStmt->close();
        }

        $totalPages = (int)ceil($totalItems / $perPage);
        if ($totalPages < 1) {
            $totalPages = 1;
        }
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $offset = max(0, ($page - 1) * $perPage);
        $listSql = "
            SELECT
            id,
              full_name,
              phone_number,
              message,
              status,
              source_page,
              source_url,
              referrer_url,
              ip_address,
              user_agent,
              created_at,
              updated_at,
              updated_by_user_id
            FROM public_donation_requests
            WHERE {$whereClause}
            ORDER BY {$sortBy} {$sortOrder}
            LIMIT ? OFFSET ?
        ";

        $listStmt = $db->prepare($listSql);
        if (!$listStmt) {
            $isError = true;
            $actionMsg = 'Failed to load request list.';
        } else {
            $listValues = $values;
            $listTypes = $types . 'ii';
            $listValues[] = $perPage;
            $listValues[] = $offset;
            bind_query_params($listStmt, $listTypes, $listValues);
            $listStmt->execute();
            $requestRows = $listStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $listStmt->close();
        }

        if (!empty($requestRows)) {
            $donorMatchCache = [];
            $cleanColumn = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(%s, '+', ''), ' ', ''), '-', ''), '(', ''), ')', '')";

            foreach ($requestRows as &$requestRow) {
                $lookupPhones = $buildPhoneLookupList($normalizeRequestPhone((string)($requestRow['phone_number'] ?? '')));
                $cacheKey = implode('|', $lookupPhones);

                if ($cacheKey === '') {
                    $requestRow['donor_match'] = null;
                    continue;
                }

                if (array_key_exists($cacheKey, $donorMatchCache)) {
                    $requestRow['donor_match'] = $donorMatchCache[$cacheKey];
                    continue;
                }

                $conditions = [];
                $matchTypes = '';
                $matchValues = [];
                foreach ($lookupPhones as $lookupPhone) {
                    $conditions[] = '(' . sprintf($cleanColumn, '`phone`') . ' = ? OR ' . sprintf($cleanColumn, '`phone_number`') . ' = ?)';
                    $matchValues[] = $lookupPhone;
                    $matchValues[] = $lookupPhone;
                    $matchTypes .= 'ss';
                }

                $matchSql = 'SELECT id, name, phone, phone_number FROM donors WHERE ' . implode(' OR ', $conditions) . ' LIMIT 1';
                $matchStmt = $db->prepare($matchSql);
                if (!$matchStmt) {
                    $requestRow['donor_match'] = null;
                    $donorMatchCache[$cacheKey] = null;
                    continue;
                }

                $matchBind = [$matchTypes];
                foreach ($matchValues as $index => $value) {
                    $matchBind[] = &$matchValues[$index];
                }
                call_user_func_array([$matchStmt, 'bind_param'], $matchBind);
                $matchStmt->execute();
                $donorMatch = $matchStmt->get_result()->fetch_assoc();
                $matchStmt->close();

                $donorMatch = $donorMatch ?: null;
                $requestRow['donor_match'] = $donorMatch;
                $donorMatchCache[$cacheKey] = $donorMatch;
            }
            unset($requestRow);
        }
    } catch (Throwable $e) {
        $isError = true;
        $actionMsg = 'Unable to load donation request records.';
        error_log('Admin Public Donations - Query failed: ' . $e->getMessage());
        $totalItems = 0;
        $totalPages = 1;
        $requestRows = [];
    }
}

$persistedParams = [
    'search' => $search,
    'status' => $statusFilter,
    'date_from' => $validatedDateFrom,
    'date_to' => $validatedDateTo,
    'sort_by' => $sortBy,
    'sort_order' => $sortOrder,
    'per_page' => $perPage,
];

$buildUrl = static function(string $path, int $targetPage, array $baseParams) use ($persistedParams): string {
    $params = $persistedParams;
    $params['page'] = (string)$targetPage;

    $filtered = [];
    foreach ($params as $key => $value) {
        if ($value !== '' && $value !== null) {
            $filtered[$key] = $value;
        }
    }

    if (!empty($baseParams)) {
        foreach ($baseParams as $key => $value) {
            if ($value !== '' && $value !== null) {
                $filtered[$key] = $value;
            } else {
                unset($filtered[$key]);
            }
        }
    }

    return $path . (empty($filtered) ? '' : ('?' . http_build_query($filtered)));
};

if ($actionMsg === '' && isset($_GET['msg']) && trim((string)$_GET['msg']) !== '') {
    $actionMsg = (string)$_GET['msg'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Public Donation Requests - Fundraising Admin</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css?v=<?php echo @filemtime(__DIR__ . '/../assets/admin.css'); ?>">
    <style>
        .request-message {
            max-width: 360px;
        }

        .status-pill {
            border-radius: 999px;
            padding: 0.3rem 0.7rem;
            font-size: 0.75rem;
            font-weight: 600;
            color: white;
            white-space: nowrap;
        }

        .text-wrap-anywhere {
            overflow-wrap: anywhere;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        .action-form {
            min-width: 220px;
        }

        .meta-small {
            color: #6c757d;
            font-size: 0.82rem;
            line-height: 1.2;
        }

        .btn-filter {
            min-width: 120px;
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="admin-content">
        <?php include __DIR__ . '/../includes/topbar.php'; ?>

        <main class="main-content">
            <div class="container-fluid">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
                    <div>
                        <h1 class="h4 mb-1">Public Donation Requests</h1>
                        <div class="text-muted">Incoming public contact requests from the donation page.</div>
                    </div>
                    <a href="index.php" class="btn btn-outline-primary btn-sm">
                        <i class="fas fa-arrow-left me-1"></i> Back to Pending Approvals
                    </a>
                </div>

                <?php if ($actionMsg !== ''): ?>
                    <div id="actionMessage" class="alert <?php echo $isError ? 'alert-danger' : 'alert-success'; ?> alert-dismissible fade show" role="alert">
                        <i class="fas fa-<?php echo $isError ? 'exclamation-triangle' : 'check-circle'; ?> me-2"></i>
                        <?php echo htmlspecialchars($actionMsg, ENT_QUOTES, 'UTF-8'); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if (!$dbConnected): ?>
                    <div class="card">
                        <div class="card-body">
                            <div class="alert alert-danger mb-3">
                                <h5 class="mb-2">Database Connection Error</h5>
                                <p class="mb-0">
                                    <?php echo htmlspecialchars($actionMsg, ENT_QUOTES, 'UTF-8'); ?>
                                </p>
                            </div>
                        </div>
                    </div>
                <?php elseif (!$requestsTableExists): ?>
                    <div class="card">
                        <div class="card-body">
                            <div class="alert alert-warning mb-3">
                                <h5 class="mb-2">Database table missing</h5>
                                <p class="mb-1">The table <strong>public_donation_requests</strong> is not present.</p>
                                <p class="mb-0">Run the SQL below in phpMyAdmin first:</p>
                            </div>
                            <pre class="bg-light p-3 rounded text-wrap">CREATE TABLE `public_donation_requests` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `full_name` varchar(120) NOT NULL,
  `phone_number` varchar(40) NOT NULL,
  `message` text NULL,
  `status` enum('new','contacted','resolved','spam') NOT NULL DEFAULT 'new',
  `source_page` varchar(255) NULL,
  `source_url` varchar(500) NULL,
  `referrer_url` varchar(500) NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` varchar(512) NULL,
  `updated_by_user_id` int unsigned NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status_created_at` (`status`,`created_at`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_phone_number` (`phone_number`),
  KEY `idx_ip_address` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;</pre>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card mb-3">
                        <div class="card-body">
                            <form method="GET" class="row g-2 align-items-end">
                                <div class="col-md-4">
                                    <label class="form-label">Search</label>
                                    <input type="text" class="form-control form-control-sm" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Name, phone, message, source, referrer">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Status</label>
                                    <select name="status" class="form-select form-select-sm">
                                        <option value="">All</option>
                                        <?php foreach ($allowedStatuses as $status): ?>
                                            <option value="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $statusFilter === $status ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($statusLabels[$status]['label'], ENT_QUOTES, 'UTF-8'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">From</label>
                                    <input type="date" class="form-control form-control-sm" name="date_from" value="<?php echo htmlspecialchars($validatedDateFrom, ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">To</label>
                                    <input type="date" class="form-control form-control-sm" name="date_to" value="<?php echo htmlspecialchars($validatedDateTo, ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Sort</label>
                                    <div class="input-group input-group-sm">
                                        <select name="sort_by" class="form-select form-select-sm">
                                            <?php
                                            $sortMap = [
                                                'created_at' => 'Created',
                                                'updated_at' => 'Updated',
                                                'full_name' => 'Name',
                                                'phone_number' => 'Phone',
                                                'status' => 'Status',
                                            ];
                                            foreach ($sortMap as $key => $label):
                                            ?>
                                                <option value="<?php echo $key; ?>" <?php echo $sortBy === $key ? 'selected' : ''; ?>>
                                                    <?php echo $label; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <select name="sort_order" class="form-select form-select-sm">
                                            <option value="desc" <?php echo $sortOrder === 'desc' ? 'selected' : ''; ?>>Desc</option>
                                            <option value="asc" <?php echo $sortOrder === 'asc' ? 'selected' : ''; ?>>Asc</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-12 d-flex gap-2 mt-2">
                                    <button class="btn btn-primary btn-filter" type="submit">
                                        <i class="fas fa-search me-1"></i> Filter
                                    </button>
                                    <a href="public-donations.php" class="btn btn-outline-secondary btn-filter">Reset</a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <div>
                                <strong>Items:</strong> <?php echo number_format($totalItems); ?>
                            </div>
                            <div class="d-flex gap-2 align-items-center">
                                <?php
                                $perPageMap = [10, 20, 50];
                                foreach ($perPageMap as $itemCount):
                                    $url = ($itemCount === $perPage)
                                        ? '#'
                                        : $buildUrl('public-donations.php', 1, ['per_page' => $itemCount]);
                                ?>
                                    <?php if ($itemCount === $perPage): ?>
                                        <span class="badge bg-primary"><?php echo $itemCount; ?></span>
                                    <?php else: ?>
                                        <a href="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary btn-sm"><?php echo $itemCount; ?></a>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="table-responsive table-wrapper">
                            <table class="table table-hover mb-0 align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>ID</th>
                                        <th>Donor</th>
                                        <th>Phone</th>
                                        <th>Message</th>
                                        <th>Where</th>
                                        <th>Existing Donor</th>
                                        <th>Status</th>
                                        <th>Submitted</th>
                                        <th>IP</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($requestRows)): ?>
                                        <tr>
                                            <td colspan="10" class="text-center py-4">No matching requests found.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($requestRows as $req): ?>
                                            <tr>
                                                <td>#<?php echo (int)$req['id']; ?></td>
                                                <td>
                                                    <div class="fw-semibold"><?php echo htmlspecialchars((string)$req['full_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                                </td>
                                                <td>
                                                    <span class="text-monospace"><?php echo htmlspecialchars((string)$req['phone_number'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                </td>
                                                <td>
                                                    <div class="request-message text-wrap-anywhere">
                                                        <?php
                                                        $msg = (string)($req['message'] ?? '');
                                                        $shortMsg = $msg === '' ? '<em class="text-muted">No message</em>' : mb_strimwidth(htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'), 0, 160, '...');
                                                        ?>
                                                        <?php if ($msg === ''): ?>
                                                            <span class="text-muted"><em>No message</em></span>
                                                        <?php else: ?>
                                                            <span title="<?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?>"><?php echo $shortMsg; ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="text-wrap-anywhere meta-small">
                                                        <?php echo htmlspecialchars((string)($req['source_page'] ?: 'public page'), ENT_QUOTES, 'UTF-8'); ?><br>
                                                        <strong>URL:</strong>
                                                        <span title="<?php echo htmlspecialchars((string)($req['source_url'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                                            <?php echo htmlspecialchars((string)($req['source_url'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?>
                                                        </span><br>
                                                        <strong>Ref:</strong>
                                                        <span title="<?php echo htmlspecialchars((string)($req['referrer_url'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                                            <?php echo htmlspecialchars((string)($req['referrer_url'] ?? 'Direct'), ENT_QUOTES, 'UTF-8'); ?>
                                                        </span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php $donorMatch = $req['donor_match'] ?? null; ?>
                                                    <?php if (!empty($donorMatch) && !empty($donorMatch['id'])): ?>
                                                        <div class="fw-semibold">
                                                            <a href="../donor-management/view-donor.php?id=<?php echo (int)$donorMatch['id']; ?>">
                                                                <i class="fas fa-user-check me-1"></i>
                                                                <?php echo htmlspecialchars((string)($donorMatch['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                                            </a>
                                                        </div>
                                                        <div class="meta-small">
                                                            Donor phone:
                                                            <span class="text-monospace"><?php echo htmlspecialchars((string)($donorMatch['phone'] ?: $donorMatch['phone_number']), ENT_QUOTES, 'UTF-8'); ?></span>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-muted">No donor match</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    $status = (string)($req['status'] ?? 'new');
                                                    $badge = $statusLabels[$status] ?? ['label' => ucfirst($status), 'class' => 'bg-secondary'];
                                                    ?>
                                                    <span class="status-pill <?php echo $badge['class']; ?>">
                                                        <?php echo htmlspecialchars($badge['label'], ENT_QUOTES, 'UTF-8'); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="meta-small">
                                                        <?php echo date('Y-m-d H:i:s', strtotime((string)$req['created_at'])); ?><br>
                                                        <span class="text-muted">Updated:</span>
                                                        <?php echo date('Y-m-d H:i:s', strtotime((string)$req['updated_at'])); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="text-monospace small"><?php echo htmlspecialchars((string)$req['ip_address'], ENT_QUOTES, 'UTF-8'); ?></span><br>
                                                    <?php $agent = (string)($req['user_agent'] ?? ''); ?>
                                                    <span class="text-muted" title="<?php echo htmlspecialchars($agent, ENT_QUOTES, 'UTF-8'); ?>">
                                                        <?php echo htmlspecialchars(substr($agent, 0, 40), ENT_QUOTES, 'UTF-8'); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <form method="POST" class="d-flex gap-2 action-form">
                                                        <?php echo csrf_input(); ?>
                                                        <input type="hidden" name="action" value="update_status">
                                                        <input type="hidden" name="request_id" value="<?php echo (int)$req['id']; ?>">
                                                          <input type="hidden" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
                                                          <input type="hidden" name="filter_status" value="<?php echo htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8'); ?>">
                                                        <input type="hidden" name="date_from" value="<?php echo htmlspecialchars($validatedDateFrom, ENT_QUOTES, 'UTF-8'); ?>">
                                                        <input type="hidden" name="date_to" value="<?php echo htmlspecialchars($validatedDateTo, ENT_QUOTES, 'UTF-8'); ?>">
                                                        <input type="hidden" name="sort_by" value="<?php echo htmlspecialchars($sortBy, ENT_QUOTES, 'UTF-8'); ?>">
                                                        <input type="hidden" name="sort_order" value="<?php echo htmlspecialchars($sortOrder, ENT_QUOTES, 'UTF-8'); ?>">
                                                        <input type="hidden" name="page" value="<?php echo (int)$page; ?>">
                                                        <input type="hidden" name="per_page" value="<?php echo (int)$perPage; ?>">
                                                        <select name="status" class="form-select form-select-sm">
                                                            <?php foreach ($allowedStatuses as $statusOption): ?>
                                                                <option value="<?php echo htmlspecialchars($statusOption, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $status === $statusOption ? 'selected' : ''; ?>>
                                                                    <?php echo htmlspecialchars(ucfirst($statusOption), ENT_QUOTES, 'UTF-8'); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <button class="btn btn-sm btn-outline-primary" type="submit">Save</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <?php if ($totalPages > 1): ?>
                        <nav aria-label="Public request pagination" class="mt-3">
                            <ul class="pagination justify-content-center">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="<?php echo htmlspecialchars($buildUrl('public-donations.php', 1, []), ENT_QUOTES, 'UTF-8'); ?>">First</a>
                                    </li>
                                    <li class="page-item">
                                        <a class="page-link" href="<?php echo htmlspecialchars($buildUrl('public-donations.php', $page - 1, []), ENT_QUOTES, 'UTF-8'); ?>">Prev</a>
                                    </li>
                                <?php else: ?>
                                    <li class="page-item disabled"><span class="page-link">First</span></li>
                                    <li class="page-item disabled"><span class="page-link">Prev</span></li>
                                <?php endif; ?>

                                <?php
                                $start = max(1, $page - 2);
                                $end = min($totalPages, $page + 2);
                                for ($i = $start; $i <= $end; $i++):
                                ?>
                                    <?php if ($i === $page): ?>
                                        <li class="page-item active"><span class="page-link"><?php echo $i; ?></span></li>
                                    <?php else: ?>
                                        <li class="page-item"><a class="page-link" href="<?php echo htmlspecialchars($buildUrl('public-donations.php', $i, []), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $i; ?></a></li>
                                    <?php endif; ?>
                                <?php endfor; ?>

                                <?php if ($page < $totalPages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="<?php echo htmlspecialchars($buildUrl('public-donations.php', $page + 1, []), ENT_QUOTES, 'UTF-8'); ?>">Next</a>
                                    </li>
                                    <li class="page-item">
                                        <a class="page-link" href="<?php echo htmlspecialchars($buildUrl('public-donations.php', $totalPages, []), ENT_QUOTES, 'UTF-8'); ?>">Last</a>
                                    </li>
                                <?php else: ?>
                                    <li class="page-item disabled"><span class="page-link">Next</span></li>
                                    <li class="page-item disabled"><span class="page-link">Last</span></li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js?v=<?php echo @filemtime(__DIR__ . '/../assets/admin.js'); ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const messages = document.querySelectorAll('.request-message span[title]');
    messages.forEach((el) => {
        el.addEventListener('dblclick', function() {
            alert(this.getAttribute('title') || '');
        });
    });
});
</script>
</body>
</html>
