<?php
/**
 * Admin - Trusted Devices Management
 * View and manage all donor trusted devices
 */

declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../shared/audit_helper.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

$db = db();
$page_title = 'Trusted Devices Management';
$current_user = current_user();

$success_message = '';
$error_message = '';

// Handle device revocation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    
    if ($action === 'revoke_device') {
        $device_id = (int)($_POST['device_id'] ?? 0);
        if ($device_id > 0) {
            // Verify device exists
            $check = $db->prepare("SELECT id, device_token, donor_id FROM donor_trusted_devices WHERE id = ?");
            $check->bind_param('i', $device_id);
            $check->execute();
            $device = $check->get_result()->fetch_assoc();
            $check->close();
            
            if ($device) {
                // Deactivate the device
                $revoke = $db->prepare("UPDATE donor_trusted_devices SET is_active = 0 WHERE id = ?");
                $revoke->bind_param('i', $device_id);
                $revoke->execute();
                $revoke->close();
                
                // Audit log
                log_audit($db, 'revoke_device', 'donor_trusted_device', $device_id, ['device_token' => substr($device['device_token'], 0, 8) . '...'], null, 'admin_portal', $current_user['id']);
                
                $success_message = 'Device revoked successfully.';
            } else {
                $error_message = 'Device not found.';
            }
        }
    } elseif ($action === 'revoke_all_donor') {
        $donor_id = (int)($_POST['donor_id'] ?? 0);
        if ($donor_id > 0) {
            $revoke_all = $db->prepare("UPDATE donor_trusted_devices SET is_active = 0 WHERE donor_id = ?");
            $revoke_all->bind_param('i', $donor_id);
            $revoke_all->execute();
            $affected = $revoke_all->affected_rows;
            $revoke_all->close();
            
            // Audit log
            log_audit($db, 'revoke_all_devices', 'donor', $donor_id, null, ['devices_revoked' => $affected], 'admin_portal', $current_user['id']);
            
            $success_message = "Revoked {$affected} device(s) for donor.";
        }
    }
}

// Check if tables exist
$tables_exist = true;
$otp_table_check = $db->query("SHOW TABLES LIKE 'donor_otp_codes'");
$device_table_check = $db->query("SHOW TABLES LIKE 'donor_trusted_devices'");
if ($otp_table_check->num_rows === 0 || $device_table_check->num_rows === 0) {
    $tables_exist = false;
}

// Fetch all trusted devices
$devices = [];
$filter_donor_id = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0;
$filter_status = $_GET['status'] ?? 'all'; // all, active, expired, revoked

if ($tables_exist) {
    $where = [];
    $params = [];
    $types = '';
    
    if ($filter_donor_id > 0) {
        $where[] = "td.donor_id = ?";
        $params[] = $filter_donor_id;
        $types .= 'i';
    }
    
    if ($filter_status === 'active') {
        $where[] = "td.is_active = 1 AND td.expires_at > NOW()";
    } elseif ($filter_status === 'expired') {
        $where[] = "td.is_active = 1 AND td.expires_at <= NOW()";
    } elseif ($filter_status === 'revoked') {
        $where[] = "td.is_active = 0";
    }
    
    $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    $sql = "
        SELECT td.*, d.name as donor_name, d.phone as donor_phone
        FROM donor_trusted_devices td
        JOIN donors d ON td.donor_id = d.id
        $where_sql
        ORDER BY td.created_at DESC
        LIMIT 500
    ";
    
    if (!empty($params)) {
        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $db->query($sql);
    }
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $devices[] = $row;
        }
        if (isset($stmt)) $stmt->close();
    }
}

// Get unique donors for filter
$donors_list = [];
if ($tables_exist) {
    $donors_query = $db->query("
        SELECT DISTINCT d.id, d.name, d.phone, COUNT(td.id) as device_count
        FROM donors d
        JOIN donor_trusted_devices td ON d.id = td.donor_id
        GROUP BY d.id
        ORDER BY d.name
        LIMIT 100
    ");
    if ($donors_query) {
        while ($row = $donors_query->fetch_assoc()) {
            $donors_list[] = $row;
        }
    }
}

/**
 * Parse user agent to get friendly device name
 */
function parseDeviceName(string $userAgent): array {
    $browser = 'Unknown Browser';
    $os = 'Unknown Device';
    $icon = 'fa-desktop';
    
    // Detect browser
    if (preg_match('/Chrome\/[\d.]+/', $userAgent) && !preg_match('/Edg/', $userAgent)) {
        $browser = 'Chrome';
    } elseif (preg_match('/Firefox\/[\d.]+/', $userAgent)) {
        $browser = 'Firefox';
    } elseif (preg_match('/Safari\/[\d.]+/', $userAgent) && !preg_match('/Chrome/', $userAgent)) {
        $browser = 'Safari';
    } elseif (preg_match('/Edg\/[\d.]+/', $userAgent)) {
        $browser = 'Edge';
    } elseif (preg_match('/MSIE|Trident/', $userAgent)) {
        $browser = 'Internet Explorer';
    } elseif (preg_match('/Opera|OPR/', $userAgent)) {
        $browser = 'Opera';
    }
    
    // Detect OS
    if (preg_match('/iPhone/', $userAgent)) {
        $os = 'iPhone';
        $icon = 'fa-mobile-alt';
    } elseif (preg_match('/iPad/', $userAgent)) {
        $os = 'iPad';
        $icon = 'fa-tablet-alt';
    } elseif (preg_match('/Android/', $userAgent)) {
        if (preg_match('/Mobile/', $userAgent)) {
            $os = 'Android Phone';
            $icon = 'fa-mobile-alt';
        } else {
            $os = 'Android Tablet';
            $icon = 'fa-tablet-alt';
        }
    } elseif (preg_match('/Windows/', $userAgent)) {
        $os = 'Windows';
        $icon = 'fa-desktop';
    } elseif (preg_match('/Macintosh/', $userAgent)) {
        $os = 'Mac';
        $icon = 'fa-laptop';
    } elseif (preg_match('/Linux/', $userAgent)) {
        $os = 'Linux';
        $icon = 'fa-desktop';
    }
    
    return [
        'browser' => $browser,
        'os' => $os,
        'icon' => $icon,
        'display' => "$browser on $os"
    ];
}

/**
 * Format relative time
 */
function timeAgo(?string $datetime): string {
    if (!$datetime) return 'Never';
    
    $time = strtotime($datetime);
    $diff = time() - $time;
    
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    if ($diff < 604800) return floor($diff / 86400) . ' days ago';
    
    return date('M j, Y', $time);
}

function h($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo h($page_title); ?> - Admin</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        .device-card {
            background: white;
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: 1rem;
            border: 1px solid #e2e8f0;
            transition: all 0.2s;
        }
        .device-card:hover {
            border-color: #cbd5e1;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        .device-card.active {
            border-color: #10b981;
            background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%);
        }
        .device-card.expired {
            border-color: #f59e0b;
            background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
        }
        .device-card.revoked {
            border-color: #e5e7eb;
            background: #f9fafb;
            opacity: 0.7;
        }
        .device-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            background: #f1f5f9;
            color: #64748b;
        }
        .device-card.active .device-icon {
            background: #dcfce7;
            color: #16a34a;
        }
        .device-card.expired .device-icon {
            background: #fef3c7;
            color: #d97706;
        }
        .device-info {
            flex: 1;
            min-width: 0;
        }
        .device-name {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.25rem;
        }
        .device-meta {
            font-size: 0.8125rem;
            color: #64748b;
        }
        .device-meta i {
            width: 16px;
            text-align: center;
            margin-right: 4px;
        }
        .status-badge {
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.25rem 0.5rem;
            border-radius: 6px;
        }
        .status-badge.active {
            background: #dcfce7;
            color: #16a34a;
        }
        .status-badge.expired {
            background: #fef3c7;
            color: #d97706;
        }
        .status-badge.revoked {
            background: #e5e7eb;
            color: #6b7280;
        }
        .filter-section {
            background: white;
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            border: 1px solid #e2e8f0;
        }
        @media (max-width: 576px) {
            .device-card {
                padding: 1rem;
            }
            .device-icon {
                width: 40px;
                height: 40px;
                font-size: 1rem;
            }
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="container-fluid p-3 p-md-4">
                
                <!-- Page Header -->
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
                    <div>
                        <h1 class="h3 mb-1">
                            <i class="fas fa-mobile-alt text-primary me-2"></i>Trusted Devices Management
                        </h1>
                        <p class="text-muted mb-0">View and manage all donor trusted devices</p>
                    </div>
                    <div>
                        <a href="donor-portal.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-1"></i>Back to Portal Dashboard
                        </a>
                    </div>
                </div>

                <?php if ($success_message): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle me-2"></i><?php echo h($success_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo h($error_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if (!$tables_exist): ?>
                <!-- Setup Required -->
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Database Setup Required</strong>
                    <p class="mb-0">The donor authentication tables haven't been created yet. Please run the SQL from the Donor Portal Dashboard.</p>
                </div>
                <?php else: ?>

                <!-- Filters -->
                <div class="filter-section">
                    <form method="GET" class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Filter by Donor</label>
                            <select name="donor_id" class="form-select">
                                <option value="0">All Donors</option>
                                <?php foreach ($donors_list as $donor): ?>
                                <option value="<?php echo $donor['id']; ?>" <?php echo $filter_donor_id === $donor['id'] ? 'selected' : ''; ?>>
                                    <?php echo h($donor['name']); ?> (<?php echo $donor['device_count']; ?> devices)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Filter by Status</label>
                            <select name="status" class="form-select">
                                <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Devices</option>
                                <option value="active" <?php echo $filter_status === 'active' ? 'selected' : ''; ?>>Active Only</option>
                                <option value="expired" <?php echo $filter_status === 'expired' ? 'selected' : ''; ?>>Expired</option>
                                <option value="revoked" <?php echo $filter_status === 'revoked' ? 'selected' : ''; ?>>Revoked</option>
                            </select>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-filter me-1"></i>Apply Filters
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Device List -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0">
                        <i class="fas fa-list me-2 text-primary"></i>
                        Devices (<?php echo count($devices); ?>)
                    </h5>
                </div>

                <?php if (empty($devices)): ?>
                <!-- No Devices -->
                <div class="text-center text-muted py-5">
                    <i class="fas fa-mobile-alt fa-3x opacity-25 mb-3"></i>
                    <p>No trusted devices found matching your filters.</p>
                </div>

                <?php else: ?>
                <?php foreach ($devices as $device): 
                    $is_active = $device['is_active'] && strtotime($device['expires_at']) > time();
                    $is_expired = $device['is_active'] && strtotime($device['expires_at']) <= time();
                    $is_revoked = !$device['is_active'];
                    
                    $card_class = 'active';
                    if ($is_expired) $card_class = 'expired';
                    if ($is_revoked) $card_class = 'revoked';
                    
                    $status_badge_class = 'active';
                    $status_text = 'Active';
                    if ($is_expired) {
                        $status_badge_class = 'expired';
                        $status_text = 'Expired';
                    } elseif ($is_revoked) {
                        $status_badge_class = 'revoked';
                        $status_text = 'Revoked';
                    }
                    
                    $device_info = parseDeviceName($device['device_name'] ?? '');
                ?>
                <div class="device-card <?php echo $card_class; ?>">
                    <div class="d-flex flex-wrap align-items-center gap-3">
                        <div class="device-icon">
                            <i class="fas <?php echo $device_info['icon']; ?>"></i>
                        </div>
                        
                        <div class="device-info">
                            <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
                                <a href="view-donor.php?id=<?php echo $device['donor_id']; ?>" class="text-decoration-none">
                                    <strong class="device-name"><?php echo h($device['donor_name']); ?></strong>
                                </a>
                                <span class="status-badge <?php echo $status_badge_class; ?>">
                                    <?php echo $status_text; ?>
                                </span>
                            </div>
                            <div class="device-meta">
                                <div class="d-flex flex-wrap gap-3 mb-1">
                                    <span>
                                        <i class="fas fa-mobile-alt"></i>
                                        <?php echo h($device_info['display']); ?>
                                    </span>
                                    <span>
                                        <i class="fas fa-phone"></i>
                                        <?php echo h($device['donor_phone']); ?>
                                    </span>
                                </div>
                                <div class="d-flex flex-wrap gap-3 mt-1">
                                    <span>
                                        <i class="fas fa-clock"></i>
                                        Last used: <?php echo timeAgo($device['last_used_at']); ?>
                                    </span>
                                    <span>
                                        <i class="fas fa-calendar-plus"></i>
                                        Added: <?php echo date('M j, Y', strtotime($device['created_at'])); ?>
                                    </span>
                                    <?php if ($device['ip_address']): ?>
                                    <span>
                                        <i class="fas fa-globe"></i>
                                        <?php echo h($device['ip_address']); ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                                <div class="mt-1">
                                    <span class="text-muted">
                                        <i class="fas fa-hourglass-end"></i>
                                        Expires: <?php echo date('M j, Y', strtotime($device['expires_at'])); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="device-actions">
                            <?php if ($is_active || $is_expired): ?>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Revoke this device?');">
                                <?php echo csrf_input(); ?>
                                <input type="hidden" name="action" value="revoke_device">
                                <input type="hidden" name="device_id" value="<?php echo $device['id']; ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm">
                                    <i class="fas fa-ban me-1"></i>Revoke
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <?php endif; ?>

                <?php endif; ?>

            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
</body>
</html>

