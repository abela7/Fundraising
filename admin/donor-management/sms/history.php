<?php
declare(strict_types=1);

// Enable error display for debugging
error_reporting(E_ALL);
ini_set('display_errors', '1');

try {
    require_once __DIR__ . '/../../../shared/auth.php';
} catch (Throwable $e) {
    die('Error loading auth.php: ' . $e->getMessage());
}

try {
    require_once __DIR__ . '/../../../config/db.php';
} catch (Throwable $e) {
    die('Error loading db.php: ' . $e->getMessage());
}

try {
    require_login();
    require_admin();
} catch (Throwable $e) {
    die('Auth error: ' . $e->getMessage());
}

$page_title = 'SMS History';
$current_user = current_user();
$db = null;

try {
    $db = db();
} catch (Throwable $e) {
    die('Database connection error: ' . $e->getMessage());
}

$sms_logs = [];
$error_message = null;
$tables_exist = false;
$total_records = 0;
$total_pages = 0;

// Check if SMS tables exist
try {
    $check = $db->query("SHOW TABLES LIKE 'sms_log'");
    $tables_exist = $check && $check->num_rows > 0;
} catch (Throwable $e) {
    $error_message = 'Error checking tables: ' . $e->getMessage();
}

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;
$offset = ($page - 1) * $per_page;

// Filters
$filter_status = $_GET['status'] ?? '';
$filter_source = $_GET['source'] ?? '';
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to = $_GET['date_to'] ?? '';
$filter_search = trim($_GET['search'] ?? '');

// Only query if tables exist
if ($tables_exist) {
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
            if ($stmt) {
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $count_result = $stmt->get_result();
                if ($count_result) {
                    $total_records = (int)($count_result->fetch_assoc()['total'] ?? 0);
                }
            }
        } else {
            $count_result = $db->query($count_sql);
            if ($count_result) {
                $total_records = (int)($count_result->fetch_assoc()['total'] ?? 0);
            }
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
            if ($stmt) {
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $sms_logs[] = $row;
                    }
                }
            }
        } else {
            $result = $db->query($sql);
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $sms_logs[] = $row;
                }
            }
        }
        
    } catch (Exception $e) {
        $error_message = 'Error loading data: ' . $e->getMessage();
    }
}

$total_pages = $total_records > 0 ? (int)ceil($total_records / $per_page) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($page_title); ?> - Fundraising Admin</title>
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
    <?php 
    try {
        include '../../includes/sidebar.php'; 
    } catch (Throwable $e) {
        echo '<div class="alert alert-danger m-3">Error loading sidebar: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
    ?>
    
    <div class="admin-content">
        <?php 
        try {
            include '../../includes/topbar.php'; 
        } catch (Throwable $e) {
            echo '<div class="alert alert-danger m-3">Error loading topbar: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
        ?>
        
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
                
                <?php if (!$tables_exist): ?>
                    <div class="alert alert-warning">
                        <h5 class="alert-heading"><i class="fas fa-database me-2"></i>Database Setup Required</h5>
                        <p class="mb-2">The SMS database tables have not been created yet.</p>
                        <p class="mb-0">Please run the setup script: <code>database/sms_system_tables.sql</code></p>
                    </div>
                <?php endif; ?>
                
                <?php if ($error_message): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($tables_exist): ?>
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
                            <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($filter_date_from); ?>">
                        </div>
                        <div class="col-6 col-md-3 col-lg-2">
                            <label class="form-label small fw-semibold">To Date</label>
                            <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($filter_date_to); ?>">
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
                                                <div><?php echo date('M j', strtotime($sms['sent_at'] ?? 'now')); ?></div>
                                                <small class="text-muted"><?php echo date('g:i A', strtotime($sms['sent_at'] ?? 'now')); ?></small>
                                            </td>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($sms['donor_name'] ?? 'Unknown'); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($sms['phone_number'] ?? ''); ?></small>
                                            </td>
                                            <td class="d-none d-md-table-cell sms-message-cell">
                                                <span title="<?php echo htmlspecialchars($sms['message_content'] ?? ''); ?>">
                                                    <?php echo htmlspecialchars(substr($sms['message_content'] ?? '', 0, 50)); ?>...
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php 
                                                    echo match($sms['status'] ?? '') {
                                                        'delivered' => 'success',
                                                        'sent' => 'info',
                                                        'failed' => 'danger',
                                                        default => 'secondary'
                                                    };
                                                ?>">
                                                    <?php echo ucfirst($sms['status'] ?? 'unknown'); ?>
                                                </span>
                                            </td>
                                            <td class="d-none d-lg-table-cell">
                                                <small class="text-muted">
                                                    <?php echo ucwords(str_replace('_', ' ', $sms['source_type'] ?? 'unknown')); ?>
                                                </small>
                                            </td>
                                            <td class="d-none d-lg-table-cell">
                                                <?php if (!empty($sms['cost_pence'])): ?>
                                                    £<?php echo number_format((float)$sms['cost_pence'] / 100, 2); ?>
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
                <?php endif; ?>
                
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/admin.js"></script>
</body>
</html>
