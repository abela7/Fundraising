<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../shared/auth.php';
require_once __DIR__ . '/../../../config/db.php';
require_login();
require_admin();

$page_title = 'SMS History';
$current_user = current_user();
$db = db();

$sms_logs = [];
$error_message = null;

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;
$offset = ($page - 1) * $per_page;
$total_records = 0;

// Filters
$filter_status = $_GET['status'] ?? '';
$filter_source = $_GET['source'] ?? '';
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to = $_GET['date_to'] ?? '';
$filter_search = trim($_GET['search'] ?? '');

try {
    // Build query
    $where_clauses = [];
    $params = [];
    $types = '';
    
    if ($filter_status) {
        $where_clauses[] = "l.status = ?";
        $params[] = $filter_status;
        $types .= 's';
    }
    
    if ($filter_source) {
        $where_clauses[] = "l.source_type = ?";
        $params[] = $filter_source;
        $types .= 's';
    }
    
    if ($filter_date_from) {
        $where_clauses[] = "DATE(l.sent_at) >= ?";
        $params[] = $filter_date_from;
        $types .= 's';
    }
    
    if ($filter_date_to) {
        $where_clauses[] = "DATE(l.sent_at) <= ?";
        $params[] = $filter_date_to;
        $types .= 's';
    }
    
    if ($filter_search) {
        $where_clauses[] = "(l.phone_number LIKE ? OR d.name LIKE ? OR l.message_content LIKE ?)";
        $search_param = "%$filter_search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= 'sss';
    }
    
    $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
    
    // Count total
    $count_sql = "SELECT COUNT(*) as total FROM sms_log l LEFT JOIN donors d ON l.donor_id = d.id $where_sql";
    if ($types) {
        $stmt = $db->prepare($count_sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $total_records = $stmt->get_result()->fetch_assoc()['total'];
    } else {
        $total_records = $db->query($count_sql)->fetch_assoc()['total'];
    }
    
    // Get records
    $sql = "
        SELECT l.*, d.name as donor_name, t.name as template_name
        FROM sms_log l
        LEFT JOIN donors d ON l.donor_id = d.id
        LEFT JOIN sms_templates t ON l.template_id = t.id
        $where_sql
        ORDER BY l.sent_at DESC
        LIMIT $per_page OFFSET $offset
    ";
    
    if ($types) {
        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $db->query($sql);
    }
    
    while ($row = $result->fetch_assoc()) {
        $sms_logs[] = $row;
    }
    
} catch (Exception $e) {
    $error_message = $e->getMessage();
}

$total_pages = ceil($total_records / $per_page);
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
        .sms-message-cell {
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .filter-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
        @media (max-width: 767px) {
            .table-responsive {
                font-size: 0.875rem;
            }
        }
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
                                <li class="breadcrumb-item active">History</li>
                            </ol>
                        </nav>
                        <h1 class="h3 mb-0">
                            <i class="fas fa-history text-primary me-2"></i>SMS History
                        </h1>
                    </div>
                    <div class="text-muted">
                        <?php echo number_format($total_records); ?> messages
                    </div>
                </div>
                
                <?php if ($error_message): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>
                
                <!-- Filters -->
                <div class="filter-card">
                    <form method="GET" class="row g-3">
                        <div class="col-6 col-md-3 col-lg-2">
                            <label class="form-label small fw-semibold">Status</label>
                            <select name="status" class="form-select form-select-sm">
                                <option value="">All</option>
                                <option value="delivered" <?php echo $filter_status === 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                                <option value="sent" <?php echo $filter_status === 'sent' ? 'selected' : ''; ?>>Sent</option>
                                <option value="failed" <?php echo $filter_status === 'failed' ? 'selected' : ''; ?>>Failed</option>
                            </select>
                        </div>
                        <div class="col-6 col-md-3 col-lg-2">
                            <label class="form-label small fw-semibold">Source</label>
                            <select name="source" class="form-select form-select-sm">
                                <option value="">All</option>
                                <option value="payment_reminder" <?php echo $filter_source === 'payment_reminder' ? 'selected' : ''; ?>>Reminder</option>
                                <option value="admin_manual" <?php echo $filter_source === 'admin_manual' ? 'selected' : ''; ?>>Manual</option>
                                <option value="call_center_manual" <?php echo $filter_source === 'call_center_manual' ? 'selected' : ''; ?>>Call Center</option>
                            </select>
                        </div>
                        <div class="col-6 col-md-3 col-lg-2">
                            <label class="form-label small fw-semibold">From Date</label>
                            <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo $filter_date_from; ?>">
                        </div>
                        <div class="col-6 col-md-3 col-lg-2">
                            <label class="form-label small fw-semibold">To Date</label>
                            <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo $filter_date_to; ?>">
                        </div>
                        <div class="col-12 col-md-6 col-lg-3">
                            <label class="form-label small fw-semibold">Search</label>
                            <input type="text" name="search" class="form-control form-control-sm" 
                                   placeholder="Phone, name, message..." value="<?php echo htmlspecialchars($filter_search); ?>">
                        </div>
                        <div class="col-12 col-lg-1 d-flex align-items-end gap-2">
                            <button type="submit" class="btn btn-primary btn-sm flex-grow-1">
                                <i class="fas fa-search"></i>
                            </button>
                            <a href="history.php" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-times"></i>
                            </a>
                        </div>
                    </form>
                </div>
                
                <!-- Results -->
                <?php if (empty($sms_logs)): ?>
                    <div class="card">
                        <div class="card-body text-center py-5">
                            <i class="fas fa-inbox fa-4x text-muted mb-3"></i>
                            <h4>No Messages Found</h4>
                            <p class="text-muted">No SMS messages match your filter criteria.</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date/Time</th>
                                        <th>Recipient</th>
                                        <th class="d-none d-md-table-cell">Message</th>
                                        <th>Status</th>
                                        <th class="d-none d-lg-table-cell">Source</th>
                                        <th class="d-none d-lg-table-cell">Cost</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($sms_logs as $sms): ?>
                                        <tr>
                                            <td>
                                                <div><?php echo date('M j', strtotime($sms['sent_at'])); ?></div>
                                                <small class="text-muted"><?php echo date('g:i A', strtotime($sms['sent_at'])); ?></small>
                                            </td>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($sms['donor_name'] ?? 'Unknown'); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($sms['phone_number']); ?></small>
                                            </td>
                                            <td class="d-none d-md-table-cell sms-message-cell">
                                                <span title="<?php echo htmlspecialchars($sms['message_content'] ?? ''); ?>">
                                                    <?php echo htmlspecialchars(substr($sms['message_content'] ?? '', 0, 50)); ?>...
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php 
                                                    echo match($sms['status']) {
                                                        'delivered' => 'success',
                                                        'sent' => 'info',
                                                        'failed' => 'danger',
                                                        default => 'secondary'
                                                    };
                                                ?>">
                                                    <?php echo ucfirst($sms['status']); ?>
                                                </span>
                                            </td>
                                            <td class="d-none d-lg-table-cell">
                                                <small class="text-muted">
                                                    <?php echo ucwords(str_replace('_', ' ', $sms['source_type'] ?? 'unknown')); ?>
                                                </small>
                                            </td>
                                            <td class="d-none d-lg-table-cell">
                                                <?php if ($sms['cost_pence']): ?>
                                                    £<?php echo number_format($sms['cost_pence'] / 100, 2); ?>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <nav class="mt-4">
                            <ul class="pagination justify-content-center flex-wrap">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?php echo $page - 1; ?>&<?php echo http_build_query(array_filter($_GET, fn($k) => $k !== 'page', ARRAY_FILTER_USE_KEY)); ?>">
                                            <i class="fas fa-chevron-left"></i>
                                        </a>
                                    </li>
                                <?php endif; ?>
                                
                                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $i; ?>&<?php echo http_build_query(array_filter($_GET, fn($k) => $k !== 'page', ARRAY_FILTER_USE_KEY)); ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>
                                
                                <?php if ($page < $total_pages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?php echo $page + 1; ?>&<?php echo http_build_query(array_filter($_GET, fn($k) => $k !== 'page', ARRAY_FILTER_USE_KEY)); ?>">
                                            <i class="fas fa-chevron-right"></i>
                                        </a>
                                    </li>
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
<script src="../../assets/admin.js"></script>
</body>
</html>

