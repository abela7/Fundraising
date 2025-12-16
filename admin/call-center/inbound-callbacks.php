<?php
/**
 * Inbound Callbacks - View and manage all inbound calls
 * Tracks every call received on the Twilio number
 */

declare(strict_types=1);

// #region agent log
$_debug_log = function($loc, $msg, $data, $hyp) { $f = fopen('c:\\xampp\\htdocs\\Fundraising\\.cursor\\debug.log', 'a'); fwrite($f, json_encode(['location'=>$loc,'message'=>$msg,'data'=>$data,'hypothesisId'=>$hyp,'timestamp'=>time()])."\n"); fclose($f); };
$_debug_log('inbound-callbacks.php:10', 'Script started', [], 'A');
// #endregion

try {
    require_once __DIR__ . '/../../shared/auth.php';
    // #region agent log
    $_debug_log('inbound-callbacks.php:15', 'auth.php loaded', [], 'A');
    // #endregion
} catch (Throwable $e) {
    // #region agent log
    $_debug_log('inbound-callbacks.php:19', 'auth.php FAILED', ['error'=>$e->getMessage()], 'A');
    // #endregion
    die('Auth load error');
}

try {
    require_once __DIR__ . '/../../config/db.php';
    // #region agent log
    $_debug_log('inbound-callbacks.php:27', 'db.php loaded', [], 'C');
    // #endregion
} catch (Throwable $e) {
    // #region agent log
    $_debug_log('inbound-callbacks.php:31', 'db.php FAILED', ['error'=>$e->getMessage()], 'C');
    // #endregion
    die('DB load error');
}

require_login();
// #region agent log
$_debug_log('inbound-callbacks.php:38', 'require_login passed', [], 'E');
// #endregion

// #region agent log
$_debug_log('inbound-callbacks.php:42', 'Checking if require_admin exists', ['exists'=>function_exists('require_admin')], 'A');
// #endregion

if (function_exists('require_admin')) {
    require_admin();
    // #region agent log
    $_debug_log('inbound-callbacks.php:48', 'require_admin passed', [], 'A');
    // #endregion
} else {
    // #region agent log
    $_debug_log('inbound-callbacks.php:52', 'require_admin does NOT exist - skipping', [], 'A');
    // #endregion
}

$db = db();
// #region agent log
$_debug_log('inbound-callbacks.php:57', 'db() called', ['db_ok'=>($db !== null)], 'C');
// #endregion

$user_id = (int)$_SESSION['user']['id'];
$user_name = $_SESSION['user']['name'] ?? 'Agent';
// #region agent log
$_debug_log('inbound-callbacks.php:63', 'Session vars set', ['user_id'=>$user_id], 'E');
// #endregion

// Handle follow-up action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'mark_followed_up') {
        $callback_id = (int)($_POST['callback_id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        
        if ($callback_id > 0) {
            $stmt = $db->prepare("
                UPDATE twilio_inbound_calls 
                SET agent_followed_up = 1, 
                    followed_up_by = ?, 
                    followed_up_at = NOW(),
                    notes = ?
                WHERE id = ?
            ");
            $stmt->bind_param('isi', $user_id, $notes, $callback_id);
            $stmt->execute();
            $stmt->close();
            
            header('Location: inbound-callbacks.php?success=1');
            exit;
        }
    }
    
    if ($_POST['action'] === 'mark_pending') {
        $callback_id = (int)($_POST['callback_id'] ?? 0);
        
        if ($callback_id > 0) {
            $stmt = $db->prepare("
                UPDATE twilio_inbound_calls 
                SET agent_followed_up = 0, 
                    followed_up_by = NULL, 
                    followed_up_at = NULL
                WHERE id = ?
            ");
            $stmt->bind_param('i', $callback_id);
            $stmt->execute();
            $stmt->close();
            
            header('Location: inbound-callbacks.php?reopened=1');
            exit;
        }
    }
}

// Check if table exists, create if not
// #region agent log
$_debug_log('inbound-callbacks.php:68', 'About to check table exists', [], 'B');
// #endregion
$tableCheck = $db->query("SHOW TABLES LIKE 'twilio_inbound_calls'");
// #region agent log
$_debug_log('inbound-callbacks.php:73', 'Table check query result', ['tableCheck'=>($tableCheck !== false)], 'B');
// #endregion
$tableExists = $tableCheck ? $tableCheck->num_rows > 0 : false;

if (!$tableExists) {
    $db->query("
        CREATE TABLE `twilio_inbound_calls` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `call_sid` VARCHAR(100) NOT NULL,
            `caller_phone` VARCHAR(20) NOT NULL,
            `donor_id` INT NULL,
            `donor_name` VARCHAR(255) NULL,
            `is_donor` TINYINT(1) DEFAULT 0,
            `menu_selection` VARCHAR(50) NULL,
            `payment_method` VARCHAR(20) NULL,
            `payment_amount` DECIMAL(10,2) NULL,
            `payment_status` ENUM('none','pending','confirmed','failed') DEFAULT 'none',
            `call_duration` INT NULL COMMENT 'Duration in seconds',
            `call_status` VARCHAR(50) NULL,
            `whatsapp_sent` TINYINT(1) DEFAULT 0,
            `sms_sent` TINYINT(1) DEFAULT 0,
            `agent_followed_up` TINYINT(1) DEFAULT 0,
            `followed_up_by` INT NULL,
            `followed_up_at` DATETIME NULL,
            `notes` TEXT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_caller_phone` (`caller_phone`),
            INDEX `idx_donor_id` (`donor_id`),
            INDEX `idx_payment_status` (`payment_status`),
            INDEX `idx_created_at` (`created_at`),
            INDEX `idx_agent_followed_up` (`agent_followed_up`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $tableExists = true;
}

$callbacks = [];
$stats = [
    'total' => 0, 
    'pending' => 0, 
    'followed_up' => 0, 
    'today' => 0,
    'donors' => 0,
    'non_donors' => 0,
    'this_week' => 0
];

// Get filter parameters
$filter = $_GET['filter'] ?? 'pending';
$search = trim($_GET['search'] ?? '');
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

if ($tableExists) {
    // Get comprehensive stats
    $statsQuery = $db->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN agent_followed_up = 0 THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN agent_followed_up = 1 THEN 1 ELSE 0 END) as followed_up,
            SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today,
            SUM(CASE WHEN is_donor = 1 THEN 1 ELSE 0 END) as donors,
            SUM(CASE WHEN is_donor = 0 THEN 1 ELSE 0 END) as non_donors,
            SUM(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as this_week
        FROM twilio_inbound_calls
    ");
    if ($statsQuery) {
        $stats = $statsQuery->fetch_assoc();
    }
    
    // Build WHERE clause
    $whereClauses = [];
    $params = [];
    $types = '';
    
    if ($filter === 'pending') {
        $whereClauses[] = 'ic.agent_followed_up = 0';
    } elseif ($filter === 'followed_up') {
        $whereClauses[] = 'ic.agent_followed_up = 1';
    } elseif ($filter === 'donors') {
        $whereClauses[] = 'ic.is_donor = 1';
    } elseif ($filter === 'non_donors') {
        $whereClauses[] = 'ic.is_donor = 0';
    } elseif ($filter === 'today') {
        $whereClauses[] = 'DATE(ic.created_at) = CURDATE()';
    }
    
    if (!empty($search)) {
        $whereClauses[] = '(ic.caller_phone LIKE ? OR ic.donor_name LIKE ? OR d.name LIKE ?)';
        $searchParam = '%' . $search . '%';
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $types .= 'sss';
    }
    
    if (!empty($date_from)) {
        $whereClauses[] = 'DATE(ic.created_at) >= ?';
        $params[] = $date_from;
        $types .= 's';
    }
    
    if (!empty($date_to)) {
        $whereClauses[] = 'DATE(ic.created_at) <= ?';
        $params[] = $date_to;
        $types .= 's';
    }
    
    $whereSQL = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';
    
    // Get callbacks
    $query = "
        SELECT ic.*, 
               d.name as donor_name_current,
               d.balance as donor_balance,
               d.total_pledged,
               d.phone as donor_phone,
               u.name as followed_up_by_name
        FROM twilio_inbound_calls ic
        LEFT JOIN donors d ON ic.donor_id = d.id
        LEFT JOIN users u ON ic.followed_up_by = u.id
        {$whereSQL}
        ORDER BY ic.agent_followed_up ASC, ic.created_at DESC
        LIMIT 200
    ";
    
    if (!empty($params)) {
        $stmt = $db->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $db->query($query);
    }
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            // Use current donor name if available, otherwise use stored name
            if ($row['donor_name_current']) {
                $row['display_name'] = $row['donor_name_current'];
            } else {
                $row['display_name'] = $row['donor_name'] ?? null;
            }
            $callbacks[] = $row;
        }
    }
}

// Helper function for time ago
function timeAgo($datetime) {
    $time = strtotime($datetime);
    $diff = time() - $time;
    
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' min' . ($mins > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 172800) {
        return 'Yesterday at ' . date('g:i A', $time);
    } else {
        return date('M j, Y g:i A', $time);
    }
}

// Format duration
function formatDuration($seconds) {
    if (!$seconds) return null;
    if ($seconds < 60) return $seconds . 's';
    $mins = floor($seconds / 60);
    $secs = $seconds % 60;
    return $mins . 'm ' . $secs . 's';
}

$page_title = 'Inbound Calls';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Call Center</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        .callback-card {
            border-left: 4px solid #0a6286;
            transition: all 0.2s;
            border-radius: 8px;
        }
        .callback-card.pending {
            border-left-color: #f59e0b;
            background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
        }
        .callback-card.followed-up {
            border-left-color: #22c55e;
            background: #f0fdf4;
        }
        .callback-card.non-donor {
            border-left-color: #6366f1;
        }
        .callback-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        .stat-card {
            border-radius: 12px;
            padding: 1.25rem;
            text-align: center;
            transition: all 0.2s;
            cursor: pointer;
            text-decoration: none;
            display: block;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .stat-card .stat-number {
            font-size: 2rem;
            font-weight: 700;
        }
        .stat-card.active {
            box-shadow: 0 0 0 3px rgba(10, 98, 134, 0.3);
        }
        .whatsapp-badge {
            background: #25D366;
            color: white;
        }
        .sms-badge {
            background: #0ea5e9;
            color: white;
        }
        .time-ago {
            font-size: 0.8rem;
            color: #64748b;
        }
        .phone-number {
            font-family: 'Courier New', monospace;
            font-weight: 600;
        }
        .filter-section {
            background: #f8fafc;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
        .donor-badge {
            background: linear-gradient(135deg, #22c55e, #16a34a);
            color: white;
        }
        .new-caller-badge {
            background: linear-gradient(135deg, #6366f1, #4f46e5);
            color: white;
        }
        .menu-selection {
            background: #e0f2fe;
            color: #0369a1;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
        }
        .empty-state {
            padding: 4rem 2rem;
            text-align: center;
        }
        .empty-state i {
            font-size: 4rem;
            color: #cbd5e1;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include __DIR__ . '/../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="container-fluid p-3 p-md-4">
                <!-- Header -->
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
                    <div>
                        <h1 class="h4 mb-1">
                            <i class="fas fa-phone-volume text-primary me-2"></i>Inbound Calls
                        </h1>
                        <p class="text-muted mb-0 small">Track and follow up on all incoming calls to your Twilio number</p>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-secondary" onclick="location.reload()">
                            <i class="fas fa-sync-alt me-1"></i>Refresh
                        </button>
                        <a href="index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-1"></i>Call Center
                        </a>
                    </div>
                </div>
                
                <?php if (isset($_GET['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i>Callback marked as followed up!
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <?php if (isset($_GET['reopened'])): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="fas fa-undo me-2"></i>Callback marked as pending again.
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <!-- Stats -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-md-4 col-lg-2">
                        <a href="?filter=all" class="stat-card bg-light text-decoration-none <?php echo $filter === 'all' ? 'active' : ''; ?>">
                            <div class="stat-number text-primary"><?php echo (int)$stats['total']; ?></div>
                            <div class="text-muted small">Total Calls</div>
                        </a>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <a href="?filter=pending" class="stat-card bg-warning bg-opacity-10 text-decoration-none <?php echo $filter === 'pending' ? 'active' : ''; ?>">
                            <div class="stat-number text-warning"><?php echo (int)$stats['pending']; ?></div>
                            <div class="text-muted small">Pending</div>
                        </a>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <a href="?filter=followed_up" class="stat-card bg-success bg-opacity-10 text-decoration-none <?php echo $filter === 'followed_up' ? 'active' : ''; ?>">
                            <div class="stat-number text-success"><?php echo (int)$stats['followed_up']; ?></div>
                            <div class="text-muted small">Followed Up</div>
                        </a>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <a href="?filter=today" class="stat-card bg-info bg-opacity-10 text-decoration-none <?php echo $filter === 'today' ? 'active' : ''; ?>">
                            <div class="stat-number text-info"><?php echo (int)$stats['today']; ?></div>
                            <div class="text-muted small">Today</div>
                        </a>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <a href="?filter=donors" class="stat-card bg-success bg-opacity-10 text-decoration-none <?php echo $filter === 'donors' ? 'active' : ''; ?>">
                            <div class="stat-number text-success"><?php echo (int)$stats['donors']; ?></div>
                            <div class="text-muted small">From Donors</div>
                        </a>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <a href="?filter=non_donors" class="stat-card text-decoration-none <?php echo $filter === 'non_donors' ? 'active' : ''; ?>" style="background: rgba(99, 102, 241, 0.1);">
                            <div class="stat-number" style="color: #6366f1;"><?php echo (int)$stats['non_donors']; ?></div>
                            <div class="text-muted small">New Callers</div>
                        </a>
                    </div>
                </div>
                
                <!-- Search & Filter -->
                <div class="filter-section">
                    <form method="GET" class="row g-2 align-items-end">
                        <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                        <div class="col-md-4">
                            <label class="form-label small mb-1">Search</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-search"></i></span>
                                <input type="text" name="search" class="form-control" 
                                       placeholder="Phone number or name..." 
                                       value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">From Date</label>
                            <input type="date" name="date_from" class="form-control" 
                                   value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">To Date</label>
                            <input type="date" name="date_to" class="form-control" 
                                   value="<?php echo htmlspecialchars($date_to); ?>">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-filter me-1"></i>Apply
                            </button>
                        </div>
                        <div class="col-md-2">
                            <a href="?filter=<?php echo htmlspecialchars($filter); ?>" class="btn btn-outline-secondary w-100">
                                <i class="fas fa-times me-1"></i>Clear
                            </a>
                        </div>
                    </form>
                </div>
                
                <!-- Results Summary -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="text-muted small">
                        Showing <?php echo count($callbacks); ?> call<?php echo count($callbacks) !== 1 ? 's' : ''; ?>
                        <?php if ($filter !== 'all'): ?>
                            (filtered by: <strong><?php echo ucfirst(str_replace('_', ' ', $filter)); ?></strong>)
                        <?php endif; ?>
                    </div>
                    <div class="btn-group btn-group-sm">
                        <a href="?filter=pending" class="btn btn-<?php echo $filter === 'pending' ? 'warning' : 'outline-warning'; ?>">
                            <i class="fas fa-clock me-1"></i>Pending
                        </a>
                        <a href="?filter=followed_up" class="btn btn-<?php echo $filter === 'followed_up' ? 'success' : 'outline-success'; ?>">
                            <i class="fas fa-check me-1"></i>Done
                        </a>
                        <a href="?filter=all" class="btn btn-<?php echo $filter === 'all' ? 'primary' : 'outline-primary'; ?>">
                            <i class="fas fa-list me-1"></i>All
                        </a>
                    </div>
                </div>
                
                <!-- Callbacks List -->
                <?php if (empty($callbacks)): ?>
                <div class="card">
                    <div class="empty-state">
                        <i class="fas fa-phone-slash"></i>
                        <h5>No calls found</h5>
                        <p class="text-muted mb-0">
                            <?php if (!empty($search) || !empty($date_from) || !empty($date_to)): ?>
                                Try adjusting your search filters
                            <?php else: ?>
                                When donors call your Twilio number, they will appear here
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                <?php else: ?>
                <div class="row">
                    <?php foreach ($callbacks as $cb): ?>
                    <div class="col-md-6 col-xl-4 mb-3">
                        <div class="card callback-card <?php 
                            echo $cb['agent_followed_up'] ? 'followed-up' : 'pending'; 
                            echo !$cb['is_donor'] ? ' non-donor' : '';
                        ?>">
                            <div class="card-body">
                                <!-- Header -->
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <h6 class="card-title mb-1">
                                            <?php if ($cb['donor_id']): ?>
                                                <a href="../donor-management/view-donor.php?id=<?php echo $cb['donor_id']; ?>" 
                                                   class="text-decoration-none fw-bold">
                                                    <?php echo htmlspecialchars($cb['display_name'] ?? 'Unknown'); ?>
                                                </a>
                                                <span class="badge donor-badge ms-1" title="Registered Donor">
                                                    <i class="fas fa-user-check"></i>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-dark fw-bold">Unknown Caller</span>
                                                <span class="badge new-caller-badge ms-1" title="New Caller">
                                                    <i class="fas fa-user-plus"></i>
                                                </span>
                                            <?php endif; ?>
                                        </h6>
                                        <div class="phone-number text-muted small">
                                            <i class="fas fa-phone me-1"></i>
                                            <a href="tel:<?php echo htmlspecialchars($cb['caller_phone']); ?>" 
                                               class="text-decoration-none text-muted">
                                                <?php echo htmlspecialchars($cb['caller_phone']); ?>
                                            </a>
                                        </div>
                                    </div>
                                    <div class="d-flex gap-1 flex-wrap justify-content-end">
                                        <?php if ($cb['whatsapp_sent']): ?>
                                        <span class="badge whatsapp-badge" title="WhatsApp sent">
                                            <i class="fab fa-whatsapp"></i>
                                        </span>
                                        <?php endif; ?>
                                        <?php if ($cb['sms_sent']): ?>
                                        <span class="badge sms-badge" title="SMS sent">
                                            <i class="fas fa-sms"></i>
                                        </span>
                                        <?php endif; ?>
                                        <?php if ($cb['agent_followed_up']): ?>
                                        <span class="badge bg-success">
                                            <i class="fas fa-check"></i> Done
                                        </span>
                                        <?php else: ?>
                                        <span class="badge bg-warning text-dark">
                                            <i class="fas fa-clock"></i> Pending
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <!-- Donor Balance -->
                                <?php if ($cb['donor_id'] && $cb['donor_balance'] > 0): ?>
                                <div class="mb-2">
                                    <span class="badge bg-danger">
                                        <i class="fas fa-exclamation-circle me-1"></i>
                                        Balance: £<?php echo number_format((float)$cb['donor_balance'], 2); ?>
                                    </span>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Menu Selection -->
                                <?php if ($cb['menu_selection']): ?>
                                <div class="mb-2">
                                    <span class="menu-selection">
                                        <i class="fas fa-list-ol me-1"></i>
                                        Selected: <?php echo htmlspecialchars($cb['menu_selection']); ?>
                                    </span>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Call Info -->
                                <div class="d-flex flex-wrap gap-2 mb-2 small text-muted">
                                    <span class="time-ago" title="<?php echo date('M j, Y g:i:s A', strtotime($cb['created_at'])); ?>">
                                        <i class="fas fa-clock me-1"></i>
                                        <?php echo timeAgo($cb['created_at']); ?>
                                    </span>
                                    <?php if ($cb['call_duration']): ?>
                                    <span>
                                        <i class="fas fa-hourglass-half me-1"></i>
                                        <?php echo formatDuration((int)$cb['call_duration']); ?>
                                    </span>
                                    <?php endif; ?>
                                    <?php if ($cb['call_status']): ?>
                                    <span>
                                        <i class="fas fa-info-circle me-1"></i>
                                        <?php echo htmlspecialchars($cb['call_status']); ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Follow-up Info -->
                                <?php if ($cb['agent_followed_up']): ?>
                                <div class="small text-muted mb-2 p-2 bg-white rounded">
                                    <i class="fas fa-user-check text-success me-1"></i>
                                    <strong><?php echo htmlspecialchars($cb['followed_up_by_name'] ?? 'Agent'); ?></strong>
                                    <span class="ms-1">
                                        <?php echo date('M j, g:i A', strtotime($cb['followed_up_at'])); ?>
                                    </span>
                                    <?php if ($cb['notes']): ?>
                                    <div class="mt-1 fst-italic">
                                        <i class="fas fa-sticky-note me-1"></i><?php echo htmlspecialchars($cb['notes']); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Actions -->
                                <div class="d-flex gap-2 flex-wrap">
                                    <?php if (!$cb['agent_followed_up']): ?>
                                        <?php if ($cb['donor_id']): ?>
                                        <a href="make-call.php?donor_id=<?php echo $cb['donor_id']; ?>" 
                                           class="btn btn-sm btn-success">
                                            <i class="fas fa-phone me-1"></i>Call Back
                                        </a>
                                        <?php else: ?>
                                        <a href="tel:<?php echo htmlspecialchars($cb['caller_phone']); ?>" 
                                           class="btn btn-sm btn-outline-success">
                                            <i class="fas fa-phone me-1"></i>Call
                                        </a>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-sm btn-primary" 
                                                onclick="showFollowUpModal(<?php echo $cb['id']; ?>, '<?php echo htmlspecialchars(addslashes($cb['display_name'] ?? $cb['caller_phone'])); ?>')">
                                            <i class="fas fa-check me-1"></i>Mark Done
                                        </button>
                                    <?php else: ?>
                                        <form method="POST" class="d-inline" 
                                              onsubmit="return confirm('Reopen this callback?');">
                                            <input type="hidden" name="action" value="mark_pending">
                                            <input type="hidden" name="callback_id" value="<?php echo $cb['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                <i class="fas fa-undo me-1"></i>Reopen
                                            </button>
                                        </form>
                                        <?php if ($cb['donor_id']): ?>
                                        <a href="../donor-management/view-donor.php?id=<?php echo $cb['donor_id']; ?>" 
                                           class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-user me-1"></i>View Donor
                                        </a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>

<!-- Follow-up Modal -->
<div class="modal fade" id="followUpModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="mark_followed_up">
                <input type="hidden" name="callback_id" id="modalCallbackId">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-check-circle me-2"></i>Mark as Followed Up
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Mark callback from <strong id="modalDonorName"></strong> as followed up?</p>
                    <div class="mb-3">
                        <label for="notes" class="form-label">Notes (optional)</label>
                        <textarea class="form-control" name="notes" id="notes" rows="3" 
                                  placeholder="e.g., Called back, discussed payment plan, will pay next week..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check me-1"></i>Mark as Done
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
    function showFollowUpModal(callbackId, donorName) {
        document.getElementById('modalCallbackId').value = callbackId;
        document.getElementById('modalDonorName').textContent = donorName;
        document.getElementById('notes').value = '';
        new bootstrap.Modal(document.getElementById('followUpModal')).show();
    }
    
    // Auto-refresh every 60 seconds if on pending filter
    <?php if ($filter === 'pending' && empty($search)): ?>
    setTimeout(function() {
        location.reload();
    }, 60000);
    <?php endif; ?>
</script>
</body>
</html>
