<?php
/**
 * Donor Message History
 * 
 * Search for donors and view comprehensive message history (SMS + WhatsApp)
 */

declare(strict_types=1);

// #region agent log - Debug logging function
function debug_log_msg($location, $message, $data = [], $hypothesisId = '') {
    // Write to root directory (web-accessible)
    $log_file = __DIR__ . '/../../debug_message_history.txt';
    $log_entry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'location' => $location,
        'message' => $message,
        'data' => $data,
        'hypothesisId' => $hypothesisId,
        'sessionId' => 'debug-session'
    ];
    @file_put_contents($log_file, json_encode($log_entry) . "\n", FILE_APPEND);
    
    // Also write to .cursor directory for local debugging
    $local_log = __DIR__ . '/../../.cursor/debug.log';
    @file_put_contents($local_log, json_encode($log_entry) . "\n", FILE_APPEND);
}
// #endregion

// #region agent log
debug_log_msg('message-history.php:1', 'Page load started', ['get_params' => $_GET, 'post_params' => $_POST], 'A');
// #endregion

$errors = [];
$debug_info = [];

try {
    // #region agent log
    debug_log_msg('message-history.php:10', 'Loading config/db.php', [], 'B');
    // #endregion
    require_once __DIR__ . '/../../config/db.php';
    
    // #region agent log
    debug_log_msg('message-history.php:13', 'Loading shared/auth.php', [], 'B');
    // #endregion
    require_once __DIR__ . '/../../shared/auth.php';
    
    // #region agent log
    debug_log_msg('message-history.php:16', 'Loading services/MessagingHelper.php', [], 'B');
    // #endregion
    require_once __DIR__ . '/../../services/MessagingHelper.php';
    
    // #region agent log
    debug_log_msg('message-history.php:19', 'Calling require_admin()', ['session_user' => isset($_SESSION['user'])], 'C');
    // #endregion
    require_admin();
    
    // #region agent log
    debug_log_msg('message-history.php:22', 'require_admin() passed, initializing db()', [], 'D');
    // #endregion
    $db = db();
    
    // #region agent log
    debug_log_msg('message-history.php:24', 'db() completed', ['db_connected' => ($db !== null)], 'D');
    // #endregion
    
    // #region agent log
    debug_log_msg('message-history.php:26', 'Creating MessagingHelper', [], 'D');
    // #endregion
    $msg = new MessagingHelper($db);
    
    // #region agent log
    debug_log_msg('message-history.php:28', 'MessagingHelper created', ['msg_helper' => ($msg !== null)], 'D');
    // #endregion
    
} catch (Exception $e) {
    // #region agent log
    debug_log_msg('message-history.php:30', 'Exception caught', ['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], 'E');
    // #endregion
    $errors[] = 'Fatal Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
    $debug_info[] = ['step' => 'Initialization', 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()];
}

// Get search term
// #region agent log
debug_log_msg('message-history.php:35', 'Parsing GET parameters', ['has_search' => isset($_GET['search']), 'has_donor_id' => isset($_GET['donor_id'])], 'F');
// #endregion
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$donorId = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0;

// Get donor info if ID provided
$donor = null;
if ($donorId && isset($db) && $db) {
    try {
        // #region agent log
        debug_log_msg('message-history.php:88', 'Fetching donor by ID', ['donorId' => $donorId, 'db_error' => $db->error], 'G');
        // #endregion
        $stmt = $db->prepare("SELECT id, name, phone FROM donors WHERE id = ?");
        if (!$stmt) {
            // #region agent log
            debug_log_msg('message-history.php:91', 'Prepare failed', ['error' => $db->error, 'errno' => $db->errno], 'G');
            // #endregion
            $errors[] = 'Database prepare error: ' . $db->error;
        } else {
            $stmt->bind_param('i', $donorId);
            $stmt->execute();
            if ($stmt->error) {
                // #region agent log
                debug_log_msg('message-history.php:97', 'Execute failed', ['error' => $stmt->error], 'G');
                // #endregion
                $errors[] = 'Database execute error: ' . $stmt->error;
            } else {
                $donor = $stmt->get_result()->fetch_assoc();
                // #region agent log
                debug_log_msg('message-history.php:102', 'Donor fetched', ['donor_found' => ($donor !== null), 'donor_data' => $donor], 'G');
                // #endregion
            }
            $stmt->close();
        }
    } catch (Exception $e) {
        // #region agent log
        debug_log_msg('message-history.php:108', 'Exception fetching donor', ['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], 'G');
        // #endregion
        $errors[] = 'Error fetching donor: ' . $e->getMessage();
    }
}

// Search for donors if search term provided
$search_results = [];
if (!empty($search_term) && !$donorId && isset($db) && $db) {
    try {
        // #region agent log
        debug_log_msg('message-history.php:105', 'Starting donor search', ['search_term' => $search_term], 'H');
        // #endregion
    $search_param = "%{$search_term}%";
    
    // Check payment table columns for reference field
    $payment_columns = [];
    $col_query = $db->query("SHOW COLUMNS FROM payments");
    while ($col = $col_query->fetch_assoc()) {
        $payment_columns[] = $col['Field'];
    }
    $payment_ref_col = in_array('transaction_ref', $payment_columns) ? 'transaction_ref' : 'reference';
    
    // Build search query - search by name, phone, or reference number
    $search_query = "
        SELECT DISTINCT
            d.id,
            d.name,
            d.phone,
            d.total_pledged,
            d.total_paid,
            d.balance,
            -- Extract reference from pledge notes
            (SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(p.notes, ' ', -1), ' ', 1)
             FROM pledges p 
             WHERE (p.donor_id = d.id OR p.donor_phone = d.phone) 
             AND p.notes REGEXP '[0-9]{4}'
             ORDER BY p.created_at DESC LIMIT 1) as reference_number
        FROM donors d
        WHERE (
            d.name LIKE ? 
            OR d.phone LIKE ?
            OR EXISTS (
                SELECT 1 FROM pledges pl 
                WHERE (pl.donor_id = d.id OR pl.donor_phone = d.phone) 
                AND pl.notes LIKE ?
            )
            OR EXISTS (
                SELECT 1 FROM payments pay 
                WHERE (pay.donor_id = d.id OR pay.donor_phone = d.phone) 
                AND pay.{$payment_ref_col} LIKE ?
            )
        )
        ORDER BY d.name
        LIMIT 50
    ";
    
    // #region agent log
    debug_log_msg('message-history.php:145', 'Preparing search query', ['query_length' => strlen($search_query), 'payment_ref_col' => $payment_ref_col], 'H');
    // #endregion
    $stmt = $db->prepare($search_query);
    if (!$stmt) {
        // #region agent log
        debug_log_msg('message-history.php:148', 'Search prepare failed', ['error' => $db->error, 'errno' => $db->errno], 'H');
        // #endregion
        $errors[] = 'Search query prepare error: ' . $db->error;
    } else {
        $stmt->bind_param('ssss', $search_param, $search_param, $search_param, $search_param);
        $stmt->execute();
        if ($stmt->error) {
            // #region agent log
            debug_log_msg('message-history.php:154', 'Search execute failed', ['error' => $stmt->error], 'H');
            // #endregion
            $errors[] = 'Search query execute error: ' . $stmt->error;
        } else {
            $result = $stmt->get_result();
            // #region agent log
            debug_log_msg('message-history.php:159', 'Search query executed', ['result_rows' => $result->num_rows], 'H');
            // #endregion
    
    while ($row = $result->fetch_assoc()) {
        // Extract 4-digit reference if not already extracted
        if (empty($row['reference_number'])) {
            $pledge_stmt = $db->prepare("
                SELECT notes FROM pledges 
                WHERE (donor_id = ? OR donor_phone = ?) 
                AND notes REGEXP '[0-9]{4}'
                ORDER BY created_at DESC LIMIT 1
            ");
            $pledge_stmt->bind_param('is', $row['id'], $row['phone']);
            $pledge_stmt->execute();
            $pledge_result = $pledge_stmt->get_result();
            if ($pledge_row = $pledge_result->fetch_assoc()) {
                if (preg_match('/\b(\d{4})\b/', $pledge_row['notes'], $matches)) {
                    $row['reference_number'] = $matches[1];
                }
            }
            // Fallback to padded donor ID
            if (empty($row['reference_number'])) {
                $row['reference_number'] = str_pad((string)$row['id'], 4, '0', STR_PAD_LEFT);
            }
        } else {
            // Fallback to padded donor ID if still empty
            if (empty($row['reference_number'])) {
                $row['reference_number'] = str_pad((string)$row['id'], 4, '0', STR_PAD_LEFT);
            }
        }
        
        $search_results[] = $row;
    }
            $stmt->close();
        }
    }
    // #region agent log
    debug_log_msg('message-history.php:175', 'Search completed', ['results_count' => count($search_results)], 'H');
    // #endregion
    } catch (Exception $e) {
        // #region agent log
        debug_log_msg('message-history.php:178', 'Search error', ['error' => $e->getMessage()], 'H');
        // #endregion
        $errors[] = 'Search error: ' . $e->getMessage();
    }
}

// Get message history if donor selected
$messages = [];
$stats = [];
if ($donorId && $donor && isset($msg) && $msg) {
    try {
        // #region agent log
        debug_log_msg('message-history.php:185', 'Getting message history', ['donorId' => $donorId], 'I');
        // #endregion
        
        // Check if message_log table exists
        if (isset($db) && $db) {
            $table_check = $db->query("SHOW TABLES LIKE 'message_log'");
            $table_exists = $table_check && $table_check->num_rows > 0;
            // #region agent log
            debug_log_msg('message-history.php:191', 'Checking message_log table', ['table_exists' => $table_exists], 'I');
            // #endregion
            if (!$table_exists) {
                $errors[] = 'The message_log table does not exist. Please run the migration: database/unified_message_logging.sql';
            }
        }
        
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        $channelFilter = $_GET['channel'] ?? null;
        
        // #region agent log
        debug_log_msg('message-history.php:201', 'Calling getDonorMessageHistory', ['donorId' => $donorId, 'limit' => $limit, 'offset' => $offset, 'channelFilter' => $channelFilter], 'I');
        // #endregion
        $messages = $msg->getDonorMessageHistory($donorId, $limit, $offset, $channelFilter);
        // #region agent log
        debug_log_msg('message-history.php:204', 'getDonorMessageHistory returned', ['messages_count' => count($messages), 'messages_sample' => array_slice($messages, 0, 2)], 'I');
        // #endregion
        // #region agent log
        debug_log_msg('message-history.php:207', 'Calling getDonorMessageStats', ['donorId' => $donorId], 'I');
        // #endregion
        $stats = $msg->getDonorMessageStats($donorId);
        // #region agent log
        debug_log_msg('message-history.php:210', 'getDonorMessageStats returned', ['stats' => $stats], 'I');
        // #endregion
    } catch (Exception $e) {
        // #region agent log
        debug_log_msg('message-history.php:213', 'Error getting message history', ['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], 'I');
        // #endregion
        $errors[] = 'Error loading message history: ' . $e->getMessage();
    }
}

// Get reference number for selected donor
$donor_reference = null;
if ($donorId && $donor && isset($db) && $db) {
    try {
        $pledge_stmt = $db->prepare("
            SELECT notes FROM pledges 
            WHERE (donor_id = ? OR donor_phone = ?) 
            ORDER BY created_at DESC LIMIT 1
        ");
        $pledge_stmt->bind_param('is', $donorId, $donor['phone']);
        $pledge_stmt->execute();
        $pledge_result = $pledge_stmt->get_result();
        if ($pledge_row = $pledge_result->fetch_assoc()) {
            if (preg_match('/\b(\d{4})\b/', $pledge_row['notes'], $matches)) {
                $donor_reference = $matches[1];
            }
        }
        if (!$donor_reference) {
            $donor_reference = str_pad((string)$donorId, 4, '0', STR_PAD_LEFT);
        }
    } catch (Exception $e) {
        $donor_reference = str_pad((string)$donorId, 4, '0', STR_PAD_LEFT);
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Message History<?= $donor ? ' - ' . htmlspecialchars($donor['name']) : '' ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        .debug-panel {
            background: #fff3cd;
            border: 2px solid #ffc107;
            padding: 15px;
            margin: 15px 0;
            border-radius: 5px;
        }
        .debug-panel h5 {
            color: #856404;
            margin-bottom: 10px;
        }
        .debug-panel pre {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 3px;
            overflow-x: auto;
            font-size: 12px;
        }
        .error-panel {
            background: #f8d7da;
            border: 2px solid #dc3545;
            padding: 15px;
            margin: 15px 0;
            border-radius: 5px;
        }
    </style>
    <style>
        body {
            background-color: #f8f9fa;
        }
        .admin-wrapper {
            min-height: 100vh;
        }
        .search-card {
            border: none;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 1.5rem;
        }
        .donor-card {
            cursor: pointer;
            transition: all 0.2s;
            border: 2px solid transparent;
        }
        .donor-card:hover {
            border-color: #0d6efd;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        .donor-card.active {
            border-color: #0d6efd;
            background-color: #e7f1ff;
        }
        .stat-card {
            border: none;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-2px);
        }
        .channel-badge { 
            font-size: 0.75rem; 
            padding: 0.25rem 0.5rem;
        }
        .status-badge { 
            font-size: 0.75rem; 
            padding: 0.25rem 0.5rem;
        }
        .message-content {
            word-wrap: break-word;
            word-break: break-word;
        }
        .message-row {
            border-left: 3px solid transparent;
            transition: all 0.2s;
        }
        .message-row:hover {
            background-color: #f8f9fa;
            border-left-color: #0d6efd;
        }
        .message-row.sms {
            border-left-color: #17a2b8;
        }
        .message-row.whatsapp {
            border-left-color: #28a745;
        }
        .message-row.failed {
            border-left-color: #dc3545;
        }
        .mobile-message-card {
            display: none;
        }
        @media (max-width: 768px) {
            .desktop-table {
                display: none;
            }
            .mobile-message-card {
                display: block;
            }
            .stat-card {
                margin-bottom: 1rem;
            }
            .donor-card {
                margin-bottom: 1rem;
            }
        }
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #6c757d;
        }
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php 
    // #region agent log
    debug_log_msg('message-history.php:390', 'Including sidebar.php', ['path' => __DIR__ . '/../includes/sidebar.php', 'file_exists' => file_exists(__DIR__ . '/../includes/sidebar.php')], 'J');
    // #endregion
    $sidebar_path = __DIR__ . '/../includes/sidebar.php';
    if (file_exists($sidebar_path)) {
        include $sidebar_path;
        // #region agent log
        debug_log_msg('message-history.php:395', 'Sidebar included successfully', [], 'J');
        // #endregion
    } else {
        // #region agent log
        debug_log_msg('message-history.php:398', 'Sidebar file not found', ['path' => $sidebar_path], 'J');
        // #endregion
        echo '<!-- Sidebar file not found: ' . htmlspecialchars($sidebar_path) . ' -->';
    }
    ?>
    
    <div class="admin-content">
        <?php 
        // #region agent log
        debug_log_msg('message-history.php:405', 'Including topbar.php', ['path' => __DIR__ . '/../includes/topbar.php', 'file_exists' => file_exists(__DIR__ . '/../includes/topbar.php')], 'J');
        // #endregion
        $topbar_path = __DIR__ . '/../includes/topbar.php';
        if (file_exists($topbar_path)) {
            include $topbar_path;
            // #region agent log
            debug_log_msg('message-history.php:410', 'Topbar included successfully', [], 'J');
            // #endregion
        } else {
            // #region agent log
            debug_log_msg('message-history.php:413', 'Topbar file not found', ['path' => $topbar_path], 'J');
            // #endregion
            echo '<!-- Topbar file not found: ' . htmlspecialchars($topbar_path) . ' -->';
        }
        ?>
        
        <main class="main-content">
            <div class="container-fluid p-3 p-md-4">
                <?php 
                // #region agent log
                debug_log_msg('message-history.php:422', 'Starting main content render', ['has_errors' => !empty($errors), 'has_donor' => isset($donor), 'has_messages' => isset($messages)], 'K');
                // #endregion
                ?>
                
                <!-- Debug Panel - Show errors if any -->
                <?php 
                // #region agent log
                debug_log_msg('message-history.php:430', 'Checking for errors', ['errors_count' => count($errors), 'errors' => $errors], 'L');
                // #endregion
                if (!empty($errors)): 
                // #region agent log
                debug_log_msg('message-history.php:433', 'Rendering error panel', [], 'L');
                // #endregion
                ?>
                    <div class="error-panel">
                        <h5><i class="fas fa-exclamation-triangle me-2"></i>Errors Detected</h5>
                        <ul>
                            <?php foreach ($errors as $error): ?>
                                <li><?= htmlspecialchars($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <p class="mb-0 mt-2">
                            <strong>Debug Log:</strong> 
                            <a href="../../debug_message_history.txt" target="_blank" class="btn btn-sm btn-warning me-2">
                                View Debug Log (Web)
                            </a>
                            <small class="text-muted">Or access via cPanel File Manager: <code>debug_message_history.txt</code> in root directory</small>
                        </p>
                    </div>
                <?php endif; ?>
                
                <!-- Debug Info Panel -->
                <?php 
                // #region agent log
                debug_log_msg('message-history.php:450', 'Rendering debug info panel', [], 'L');
                // #endregion
                ?>
                <div class="debug-panel">
                    <h5><i class="fas fa-bug me-2"></i>Debug Information</h5>
                    <p><strong>PHP Version:</strong> <?= PHP_VERSION ?></p>
                    <p><strong>Session Status:</strong> <?= session_status() === PHP_SESSION_ACTIVE ? 'Active' : 'Inactive' ?></p>
                    <p><strong>User Logged In:</strong> <?= isset($_SESSION['user']) ? 'Yes' : 'No' ?></p>
                    <?php if (isset($_SESSION['user'])): ?>
                        <p><strong>User Role:</strong> <?= htmlspecialchars($_SESSION['user']['role'] ?? 'unknown') ?></p>
                    <?php endif; ?>
                    <p><strong>DB Connection:</strong> <?= isset($db) && $db ? 'Connected' : 'Not Connected' ?></p>
                    <p><strong>MessagingHelper:</strong> <?= isset($msg) && $msg ? 'Initialized' : 'Not Initialized' ?></p>
                    <p><strong>GET Params:</strong> <code><?= htmlspecialchars(json_encode($_GET)) ?></code></p>
                    <p class="mb-0">
                        <strong>View Debug Log:</strong> 
                        <a href="../../debug_message_history.txt" target="_blank" class="btn btn-sm btn-info me-2">
                            Open Debug Log (Web)
                        </a>
                        <small class="text-muted">Or access via cPanel File Manager: <code>debug_message_history.txt</code> in root directory</small>
                    </p>
                </div>
                
                <!-- Page Header -->
                <?php 
                // #region agent log
                debug_log_msg('message-history.php:470', 'Rendering page header', [], 'L');
                // #endregion
                ?>
                <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
                    <div>
                        <h2 class="mb-1">
                            <i class="fas fa-envelope me-2"></i>Message History
                        </h2>
                        <p class="text-muted mb-0">Search for donors and view their message history</p>
                    </div>
                    <a href="donors.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i>Back to Donors
                    </a>
                </div>
                
                <!-- Search Card -->
                <?php 
                // #region agent log
                debug_log_msg('message-history.php:485', 'Rendering search card', ['search_term' => $search_term, 'donorId' => $donorId], 'L');
                // #endregion
                ?>
                <div class="card search-card">
                    <div class="card-body">
                        <form method="GET" action="" class="row g-3">
                            <div class="col-12 col-md-8">
                                <label class="form-label fw-bold">
                                    <i class="fas fa-search me-1"></i>Search Donor
                                </label>
                                <input 
                                    type="text" 
                                    class="form-control form-control-lg" 
                                    name="search" 
                                    value="<?= htmlspecialchars($search_term) ?>" 
                                    placeholder="Search by name, phone number, or reference number..."
                                    autofocus
                                >
                                <small class="text-muted">Enter donor name, phone number, or 4-digit reference number</small>
                            </div>
                            <div class="col-12 col-md-4 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary btn-lg w-100">
                                    <i class="fas fa-search me-2"></i>Search
                                </button>
                            </div>
                            <?php if ($donorId): ?>
                                <input type="hidden" name="donor_id" value="<?= $donorId ?>">
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
                
                <!-- Search Results -->
                <?php 
                // #region agent log
                debug_log_msg('message-history.php:510', 'Checking search results', [
                    'has_search_term' => !empty($search_term),
                    'has_donorId' => !empty($donorId),
                    'search_results_count' => count($search_results)
                ], 'L');
                // #endregion
                if (!empty($search_term) && empty($donorId) && !empty($search_results)): 
                // #region agent log
                debug_log_msg('message-history.php:518', 'Rendering search results', ['count' => count($search_results)], 'L');
                // #endregion
                ?>
                    <div class="card mb-4">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0">
                                <i class="fas fa-users me-2"></i>Search Results (<?= count($search_results) ?>)
                            </h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="row g-2 p-3">
                                <?php foreach ($search_results as $result): ?>
                                    <div class="col-12 col-md-6 col-lg-4">
                                        <a href="?donor_id=<?= $result['id'] ?>&search=<?= urlencode($search_term) ?>" 
                                           class="text-decoration-none">
                                            <div class="card donor-card h-100">
                                                <div class="card-body">
                                                    <div class="d-flex align-items-start">
                                                        <div class="avatar-circle bg-primary text-white me-3" style="width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 1.2rem;">
                                                            <?= strtoupper(substr($result['name'], 0, 1)) ?>
                                                        </div>
                                                        <div class="flex-grow-1">
                                                            <h6 class="mb-1 fw-bold"><?= htmlspecialchars($result['name']) ?></h6>
                                                            <p class="text-muted mb-1 small">
                                                                <i class="fas fa-phone me-1"></i><?= htmlspecialchars($result['phone']) ?>
                                                            </p>
                                                            <span class="badge bg-light text-dark">
                                                                Ref: #<?= htmlspecialchars($result['reference_number']) ?>
                                                            </span>
                                                        </div>
                                                        <i class="fas fa-chevron-right text-muted"></i>
                                                    </div>
                                                </div>
                                            </div>
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php elseif (!empty($search_term) && empty($donorId) && empty($search_results)): ?>
                    <div class="card">
                        <div class="card-body">
                            <div class="empty-state">
                                <i class="fas fa-search"></i>
                                <h5>No donors found</h5>
                                <p>Try searching with a different term</p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Donor Message History -->
                <?php 
                // #region agent log
                debug_log_msg('message-history.php:560', 'Checking donor message history', [
                    'has_donorId' => !empty($donorId),
                    'has_donor' => isset($donor) && $donor !== null,
                    'has_messages' => isset($messages),
                    'messages_count' => isset($messages) ? count($messages) : 0
                ], 'L');
                // #endregion
                if ($donorId && $donor): 
                // #region agent log
                debug_log_msg('message-history.php:570', 'Rendering donor message history', [], 'L');
                // #endregion
                ?>
                    <!-- Selected Donor Info -->
                    <div class="card mb-4">
                        <div class="card-body">
                            <div class="d-flex align-items-center justify-content-between flex-wrap">
                                <div class="d-flex align-items-center">
                                    <div class="avatar-circle bg-primary text-white me-3" style="width: 60px; height: 60px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 1.5rem;">
                                        <?= strtoupper(substr($donor['name'], 0, 1)) ?>
                                    </div>
                                    <div>
                                        <h4 class="mb-1"><?= htmlspecialchars($donor['name']) ?></h4>
                                        <p class="text-muted mb-0">
                                            <i class="fas fa-phone me-1"></i><?= htmlspecialchars($donor['phone']) ?>
                                            <span class="ms-3">
                                                <i class="fas fa-hashtag me-1"></i>Ref: #<?= htmlspecialchars($donor_reference) ?>
                                            </span>
                                        </p>
                                    </div>
                                </div>
                                <a href="view-donor.php?id=<?= $donorId ?>" class="btn btn-outline-primary">
                                    <i class="fas fa-user me-1"></i>View Profile
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Statistics Cards -->
                    <div class="row g-3 mb-4">
                        <div class="col-6 col-md-3">
                            <div class="card stat-card text-center">
                                <div class="card-body">
                                    <h6 class="text-muted mb-2">Total</h6>
                                    <h3 class="mb-0"><?= number_format($stats['total_messages'] ?? 0) ?></h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="card stat-card text-center">
                                <div class="card-body">
                                    <h6 class="text-muted mb-2">SMS</h6>
                                    <h3 class="mb-0 text-info"><?= number_format($stats['sms_count'] ?? 0) ?></h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="card stat-card text-center">
                                <div class="card-body">
                                    <h6 class="text-muted mb-2">WhatsApp</h6>
                                    <h3 class="mb-0 text-success"><?= number_format($stats['whatsapp_count'] ?? 0) ?></h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="card stat-card text-center">
                                <div class="card-body">
                                    <h6 class="text-muted mb-2">Delivered</h6>
                                    <h3 class="mb-0 text-success"><?= number_format($stats['delivered_count'] ?? 0) ?></h3>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filters -->
                    <div class="card mb-4">
                        <div class="card-body">
                            <form method="GET" class="row g-3">
                                <input type="hidden" name="donor_id" value="<?= $donorId ?>">
                                <?php if (!empty($search_term)): ?>
                                    <input type="hidden" name="search" value="<?= htmlspecialchars($search_term) ?>">
                                <?php endif; ?>
                                <div class="col-12 col-md-4">
                                    <label class="form-label">Channel</label>
                                    <select name="channel" class="form-select">
                                        <option value="">All Channels</option>
                                        <option value="sms" <?= ($_GET['channel'] ?? '') === 'sms' ? 'selected' : '' ?>>SMS Only</option>
                                        <option value="whatsapp" <?= ($_GET['channel'] ?? '') === 'whatsapp' ? 'selected' : '' ?>>WhatsApp Only</option>
                                    </select>
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label">Limit</label>
                                    <select name="limit" class="form-select">
                                        <option value="25" <?= ($_GET['limit'] ?? 50) == 25 ? 'selected' : '' ?>>25</option>
                                        <option value="50" <?= ($_GET['limit'] ?? 50) == 50 ? 'selected' : '' ?>>50</option>
                                        <option value="100" <?= ($_GET['limit'] ?? 50) == 100 ? 'selected' : '' ?>>100</option>
                                    </select>
                                </div>
                                <div class="col-12 col-md-4 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary me-2">
                                        <i class="fas fa-filter me-1"></i>Filter
                                    </button>
                                    <a href="?donor_id=<?= $donorId ?>" class="btn btn-outline-secondary">
                                        <i class="fas fa-times me-1"></i>Clear
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Message List - Desktop Table -->
                    <div class="card desktop-table">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-list me-2"></i>Message History
                            </h5>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($messages)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-inbox"></i>
                                    <h5>No messages found</h5>
                                    <p>This donor hasn't received any messages yet.</p>
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
                                                <?php
                                                $rowClass = 'message-row';
                                                if ($message['channel'] === 'sms') $rowClass .= ' sms';
                                                if ($message['channel'] === 'whatsapp') $rowClass .= ' whatsapp';
                                                if ($message['status'] === 'failed') $rowClass .= ' failed';
                                                ?>
                                                <tr class="<?= $rowClass ?>">
                                                    <td>
                                                        <small>
                                                            <?= date('d M Y', strtotime($message['sent_at'])) ?><br>
                                                            <span class="text-muted"><?= date('H:i', strtotime($message['sent_at'])) ?></span>
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
                                                        <div class="message-content" style="max-width: 300px;">
                                                            <?= htmlspecialchars(mb_substr($message['message_content'], 0, 80)) ?>
                                                            <?= mb_strlen($message['message_content']) > 80 ? '...' : '' ?>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <?php if ($message['template_key']): ?>
                                                            <code class="small"><?= htmlspecialchars($message['template_key']) ?></code>
                                                        <?php else: ?>
                                                            <span class="text-muted small">Direct</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($message['sent_by_name']): ?>
                                                            <strong class="small"><?= htmlspecialchars($message['sent_by_name']) ?></strong>
                                                            <br><small class="text-muted"><?= htmlspecialchars($message['sent_by_role'] ?? 'system') ?></small>
                                                        <?php else: ?>
                                                            <span class="text-muted small">System</span>
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
                                                                <i class="fas fa-check-circle me-1"></i>Delivered<br>
                                                                <?php if ($message['delivery_time_seconds']): ?>
                                                                    <span class="text-muted"><?= round($message['delivery_time_seconds'] / 60, 1) ?>m</span>
                                                                <?php endif; ?>
                                                            </small>
                                                        <?php elseif ($message['read_at']): ?>
                                                            <small class="text-success">
                                                                <i class="fas fa-eye me-1"></i>Read<br>
                                                                <?php if ($message['read_time_seconds']): ?>
                                                                    <span class="text-muted"><?= round($message['read_time_seconds'] / 60, 1) ?>m</span>
                                                                <?php endif; ?>
                                                            </small>
                                                        <?php elseif ($message['failed_at']): ?>
                                                            <small class="text-danger">
                                                                <i class="fas fa-times-circle me-1"></i>Failed
                                                            </small>
                                                        <?php else: ?>
                                                            <small class="text-muted">Pending</small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($message['cost_pence']): ?>
                                                            <small>£<?= number_format($message['cost_pence'] / 100, 2) ?></small>
                                                        <?php else: ?>
                                                            <span class="text-muted small">-</span>
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
                    
                    <!-- Message List - Mobile Cards -->
                    <div class="mobile-message-card">
                        <?php if (empty($messages)): ?>
                            <div class="card">
                                <div class="card-body">
                                    <div class="empty-state">
                                        <i class="fas fa-inbox"></i>
                                        <h5>No messages found</h5>
                                        <p>This donor hasn't received any messages yet.</p>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($messages as $message): ?>
                                <?php
                                $cardClass = 'mb-3';
                                if ($message['channel'] === 'sms') $cardClass .= ' border-start border-info border-3';
                                if ($message['channel'] === 'whatsapp') $cardClass .= ' border-start border-success border-3';
                                if ($message['status'] === 'failed') $cardClass .= ' border-start border-danger border-3';
                                
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
                                <div class="card <?= $cardClass ?>">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <div>
                                                <small class="text-muted">
                                                    <?= date('d M Y H:i', strtotime($message['sent_at'])) ?>
                                                </small>
                                            </div>
                                            <div>
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
                                                <span class="badge <?= $badgeClass ?> status-badge ms-1">
                                                    <?= ucfirst($status) ?>
                                                </span>
                                            </div>
                                        </div>
                                        
                                        <div class="message-content mb-2">
                                            <?= nl2br(htmlspecialchars($message['message_content'])) ?>
                                        </div>
                                        
                                        <div class="row g-2 small text-muted">
                                            <div class="col-6">
                                                <i class="fas fa-user me-1"></i>
                                                <?= $message['sent_by_name'] ? htmlspecialchars($message['sent_by_name']) : 'System' ?>
                                            </div>
                                            <div class="col-6 text-end">
                                                <?php if ($message['template_key']): ?>
                                                    <code><?= htmlspecialchars($message['template_key']) ?></code>
                                                <?php else: ?>
                                                    Direct
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($message['delivered_at']): ?>
                                                <div class="col-12">
                                                    <i class="fas fa-check-circle text-success me-1"></i>
                                                    Delivered
                                                    <?php if ($message['delivery_time_seconds']): ?>
                                                        (<?= round($message['delivery_time_seconds'] / 60, 1) ?> min)
                                                    <?php endif; ?>
                                                </div>
                                            <?php elseif ($message['read_at']): ?>
                                                <div class="col-12">
                                                    <i class="fas fa-eye text-success me-1"></i>
                                                    Read
                                                    <?php if ($message['read_time_seconds']): ?>
                                                        (<?= round($message['read_time_seconds'] / 60, 1) ?> min)
                                                    <?php endif; ?>
                                                </div>
                                            <?php elseif ($message['failed_at']): ?>
                                                <div class="col-12">
                                                    <i class="fas fa-times-circle text-danger me-1"></i>
                                                    Failed
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($message['cost_pence']): ?>
                                                <div class="col-12">
                                                    <i class="fas fa-pound-sign me-1"></i>
                                                    Cost: £<?= number_format($message['cost_pence'] / 100, 2) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
// Fallback for sidebar toggle if admin.js doesn't load
if (typeof window.toggleSidebar !== 'function') {
    window.toggleSidebar = function() {
        const sidebar = document.getElementById('sidebar');
        if (sidebar) {
            sidebar.classList.toggle('active');
        }
    };
}

// Ensure sidebar toggle works
document.addEventListener('DOMContentLoaded', function() {
    // #region agent log
    console.log('=== Sidebar Toggle Debug ===');
    console.log('toggleSidebar function exists:', typeof toggleSidebar !== 'undefined');
    console.log('Sidebar element:', document.getElementById('sidebar'));
    console.log('Sidebar overlay:', document.querySelector('.sidebar-overlay'));
    console.log('Window width:', window.innerWidth);
    // #endregion
    
    // Find all sidebar toggle buttons (including topbar-toggle)
    const sidebarToggleButtons = document.querySelectorAll('.sidebar-toggle, .topbar-toggle, [onclick*="toggleSidebar"], .sidebar-close');
    console.log('Found toggle buttons:', sidebarToggleButtons.length);
    sidebarToggleButtons.forEach((btn, idx) => {
        console.log('Toggle button ' + idx + ':', btn.className, btn.tagName);
    });
    
    sidebarToggleButtons.forEach(function(button) {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            console.log('Toggle button clicked');
            if (typeof toggleSidebar === 'function') {
                toggleSidebar();
            } else {
                console.error('toggleSidebar function not found!');
                // Fallback manual toggle
                const sidebar = document.getElementById('sidebar');
                const overlay = document.querySelector('.sidebar-overlay');
                if (sidebar) {
                    sidebar.classList.toggle('active');
                    if (overlay) overlay.classList.toggle('active');
                    document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
                }
            }
        });
    });
    
    // Also handle hamburger menu if it exists
    const hamburger = document.querySelector('.hamburger, [data-bs-toggle="sidebar"]');
    if (hamburger) {
        hamburger.addEventListener('click', function(e) {
            e.preventDefault();
            if (typeof toggleSidebar === 'function') {
                toggleSidebar();
            }
        });
    }
    
    console.log('Sidebar toggle initialization complete');
});
</script>
<?php 
// #region agent log
debug_log_msg('message-history.php:end', 'Page render completed', [
    'has_errors' => !empty($errors),
    'has_donor' => isset($donor) && $donor !== null,
    'has_messages' => isset($messages),
    'messages_count' => isset($messages) ? count($messages) : 0,
    'search_results_count' => isset($search_results) ? count($search_results) : 0
], 'M');
// #endregion
?>
</body>
</html>
