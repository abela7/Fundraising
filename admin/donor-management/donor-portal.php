<?php
/**
 * Admin Dashboard - Donor Portal Management
 * 
 * Monitor and manage donor authentication:
 * - SMS OTP statistics
 * - Trusted devices overview
 * - Recent logins
 * - Device management
 */

declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../shared/audit_helper.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

$db = db();
$page_title = 'Donor Portal Dashboard';
$current_user = current_user();

$success_message = '';
$error_message = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    
    if ($action === 'revoke_device') {
        $device_id = (int)($_POST['device_id'] ?? 0);
        if ($device_id > 0) {
            $stmt = $db->prepare("UPDATE donor_trusted_devices SET is_active = 0 WHERE id = ?");
            $stmt->bind_param('i', $device_id);
            if ($stmt->execute()) {
                log_audit($db, 'revoke_device', 'donor_trusted_device', $device_id, null, ['revoked_by' => 'admin'], 'admin_portal', $current_user['id']);
                $success_message = 'Device revoked successfully.';
            }
            $stmt->close();
        }
    } elseif ($action === 'revoke_all_donor_devices') {
        $donor_id = (int)($_POST['donor_id'] ?? 0);
        if ($donor_id > 0) {
            $stmt = $db->prepare("UPDATE donor_trusted_devices SET is_active = 0 WHERE donor_id = ?");
            $stmt->bind_param('i', $donor_id);
            if ($stmt->execute()) {
                $affected = $stmt->affected_rows;
                log_audit($db, 'revoke_all_devices', 'donor', $donor_id, null, ['devices_revoked' => $affected], 'admin_portal', $current_user['id']);
                $success_message = "Revoked {$affected} device(s) for donor.";
            }
            $stmt->close();
        }
    } elseif ($action === 'cleanup_expired') {
        // Clean up expired OTPs and devices
        $db->query("DELETE FROM donor_otp_codes WHERE expires_at < NOW()");
        $otp_deleted = $db->affected_rows;
        
        $db->query("UPDATE donor_trusted_devices SET is_active = 0 WHERE expires_at < NOW() AND is_active = 1");
        $devices_expired = $db->affected_rows;
        
        log_audit($db, 'cleanup', 'donor_auth', 0, null, ['otp_deleted' => $otp_deleted, 'devices_expired' => $devices_expired], 'admin_portal', $current_user['id']);
        $success_message = "Cleanup complete: {$otp_deleted} expired OTPs deleted, {$devices_expired} expired devices deactivated.";
    }
}

// Check if tables exist
$tables_exist = true;
$otp_table_check = $db->query("SHOW TABLES LIKE 'donor_otp_codes'");
$device_table_check = $db->query("SHOW TABLES LIKE 'donor_trusted_devices'");
if ($otp_table_check->num_rows === 0 || $device_table_check->num_rows === 0) {
    $tables_exist = false;
}

// Statistics
$stats = [
    'total_trusted_devices' => 0,
    'active_trusted_devices' => 0,
    'donors_with_devices' => 0,
    'otp_sent_today' => 0,
    'otp_verified_today' => 0,
    'logins_today' => 0,
    'logins_this_week' => 0,
];

if ($tables_exist) {
    // Trusted devices stats
    $result = $db->query("SELECT COUNT(*) as total FROM donor_trusted_devices");
    $stats['total_trusted_devices'] = (int)$result->fetch_assoc()['total'];
    
    $result = $db->query("SELECT COUNT(*) as active FROM donor_trusted_devices WHERE is_active = 1 AND expires_at > NOW()");
    $stats['active_trusted_devices'] = (int)$result->fetch_assoc()['active'];
    
    $result = $db->query("SELECT COUNT(DISTINCT donor_id) as donors FROM donor_trusted_devices WHERE is_active = 1 AND expires_at > NOW()");
    $stats['donors_with_devices'] = (int)$result->fetch_assoc()['donors'];
    
    // OTP stats
    $result = $db->query("SELECT COUNT(*) as sent FROM donor_otp_codes WHERE DATE(created_at) = CURDATE()");
    $stats['otp_sent_today'] = (int)$result->fetch_assoc()['sent'];
    
    $result = $db->query("SELECT COUNT(*) as verified FROM donor_otp_codes WHERE DATE(created_at) = CURDATE() AND verified = 1");
    $stats['otp_verified_today'] = (int)$result->fetch_assoc()['verified'];
    
    // Login stats from audit logs
    $result = $db->query("SELECT COUNT(*) as logins FROM audit_logs WHERE entity_type = 'donor' AND action = 'login' AND DATE(created_at) = CURDATE()");
    $stats['logins_today'] = (int)$result->fetch_assoc()['logins'];
    
    $result = $db->query("SELECT COUNT(*) as logins FROM audit_logs WHERE entity_type = 'donor' AND action = 'login' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $stats['logins_this_week'] = (int)$result->fetch_assoc()['logins'];
}

// Recent logins (from audit logs)
$recent_logins = [];
$login_query = $db->query("
    SELECT al.*, d.name as donor_name, d.phone as donor_phone
    FROM audit_logs al
    LEFT JOIN donors d ON al.entity_id = d.id
    WHERE al.entity_type = 'donor' AND al.action = 'login'
    ORDER BY al.created_at DESC
    LIMIT 20
");
if ($login_query) {
    while ($row = $login_query->fetch_assoc()) {
        $recent_logins[] = $row;
    }
}

// Recent trusted devices
$recent_devices = [];
if ($tables_exist) {
    $device_query = $db->query("
        SELECT td.*, d.name as donor_name, d.phone as donor_phone
        FROM donor_trusted_devices td
        JOIN donors d ON td.donor_id = d.id
        ORDER BY td.created_at DESC
        LIMIT 20
    ");
    if ($device_query) {
        while ($row = $device_query->fetch_assoc()) {
            $recent_devices[] = $row;
        }
    }
}

// Donors with most devices
$top_donors = [];
if ($tables_exist) {
    $top_query = $db->query("
        SELECT d.id, d.name, d.phone, COUNT(td.id) as device_count,
               SUM(CASE WHEN td.is_active = 1 AND td.expires_at > NOW() THEN 1 ELSE 0 END) as active_count
        FROM donors d
        JOIN donor_trusted_devices td ON d.id = td.donor_id
        GROUP BY d.id
        ORDER BY device_count DESC
        LIMIT 10
    ");
    if ($top_query) {
        while ($row = $top_query->fetch_assoc()) {
            $top_donors[] = $row;
        }
    }
}

/**
 * Parse user agent
 */
function parseUA(string $ua): string {
    $browser = 'Unknown';
    $os = 'Unknown';
    
    if (preg_match('/Chrome/', $ua) && !preg_match('/Edg/', $ua)) $browser = 'Chrome';
    elseif (preg_match('/Firefox/', $ua)) $browser = 'Firefox';
    elseif (preg_match('/Safari/', $ua) && !preg_match('/Chrome/', $ua)) $browser = 'Safari';
    elseif (preg_match('/Edg/', $ua)) $browser = 'Edge';
    
    if (preg_match('/iPhone/', $ua)) $os = 'iPhone';
    elseif (preg_match('/Android/', $ua)) $os = 'Android';
    elseif (preg_match('/Windows/', $ua)) $os = 'Windows';
    elseif (preg_match('/Macintosh/', $ua)) $os = 'Mac';
    
    return "$browser / $os";
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
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.25rem;
            border: 1px solid #e2e8f0;
            transition: all 0.2s;
        }
        .stat-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }
        .stat-value {
            font-size: 1.75rem;
            font-weight: 700;
            color: #1e293b;
        }
        .stat-label {
            color: #64748b;
            font-size: 0.875rem;
        }
        .portal-link {
            background: linear-gradient(135deg, #0a6286 0%, #084d68 100%);
            color: white;
            border-radius: 12px;
            padding: 1.5rem;
            text-decoration: none;
            display: block;
            transition: all 0.2s;
        }
        .portal-link:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(10, 98, 134, 0.3);
            color: white;
        }
        .table-device-name {
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .setup-alert {
            background: linear-gradient(135deg, #fef3c7 0%, #fef9c3 100%);
            border: 1px solid #fcd34d;
            border-radius: 12px;
            padding: 1.5rem;
        }
        .nav-pills .nav-link {
            border-radius: 8px;
        }
        .nav-pills .nav-link.active {
            background: #0a6286;
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
                            <i class="fas fa-user-shield text-primary me-2"></i>Donor Portal Dashboard
                        </h1>
                        <p class="text-muted mb-0">Monitor SMS OTP authentication and trusted devices</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="../../donor/" target="_blank" class="btn btn-outline-primary">
                            <i class="fas fa-external-link-alt me-1"></i>Open Portal
                        </a>
                        <form method="POST" class="d-inline">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="action" value="cleanup_expired">
                            <button type="submit" class="btn btn-outline-secondary">
                                <i class="fas fa-broom me-1"></i>Cleanup Expired
                            </button>
                        </form>
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
                <div class="setup-alert">
                    <div class="d-flex gap-3">
                        <i class="fas fa-exclamation-triangle fa-2x text-warning"></i>
                        <div>
                            <h5 class="mb-2">Database Setup Required</h5>
                            <p class="mb-3">The donor authentication tables haven't been created yet. Run the following SQL in phpMyAdmin:</p>
                            <div class="bg-dark text-light p-3 rounded" style="font-family: monospace; font-size: 0.875rem; overflow-x: auto;">
                                <pre class="mb-0" style="white-space: pre-wrap;">-- Run this SQL in phpMyAdmin
CREATE TABLE IF NOT EXISTS donor_otp_codes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    phone VARCHAR(20) NOT NULL,
    code VARCHAR(6) NOT NULL,
    expires_at DATETIME NOT NULL,
    attempts INT DEFAULT 0,
    verified TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS donor_trusted_devices (
    id INT PRIMARY KEY AUTO_INCREMENT,
    donor_id INT NOT NULL,
    device_token VARCHAR(64) NOT NULL UNIQUE,
    device_name VARCHAR(255) NULL,
    ip_address VARCHAR(45) NULL,
    last_used_at DATETIME NULL,
    expires_at DATETIME NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_donor (donor_id),
    INDEX idx_token (device_token),
    FOREIGN KEY (donor_id) REFERENCES donors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;</pre>
                            </div>
                            <button class="btn btn-primary mt-3" onclick="location.reload()">
                                <i class="fas fa-sync-alt me-1"></i>Refresh After Setup
                            </button>
                        </div>
                    </div>
                </div>
                <?php else: ?>

                <!-- Quick Link to Portal -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <a href="../../donor/" target="_blank" class="portal-link">
                            <div class="d-flex align-items-center gap-3">
                                <div style="width: 50px; height: 50px; background: rgba(255,255,255,0.2); border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                                    <i class="fas fa-user-circle fa-lg"></i>
                                </div>
                                <div>
                                    <h5 class="mb-1">Donor Portal</h5>
                                    <p class="mb-0 opacity-75">donor/login.php - SMS OTP Login</p>
                                </div>
                                <i class="fas fa-external-link-alt ms-auto opacity-75"></i>
                            </div>
                        </a>
                    </div>
                    <div class="col-md-6">
                        <a href="../../donor/trusted-devices.php" target="_blank" class="portal-link" style="background: linear-gradient(135deg, #059669 0%, #047857 100%);">
                            <div class="d-flex align-items-center gap-3">
                                <div style="width: 50px; height: 50px; background: rgba(255,255,255,0.2); border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                                    <i class="fas fa-mobile-alt fa-lg"></i>
                                </div>
                                <div>
                                    <h5 class="mb-1">Trusted Devices Page</h5>
                                    <p class="mb-0 opacity-75">donor/trusted-devices.php</p>
                                </div>
                                <i class="fas fa-external-link-alt ms-auto opacity-75"></i>
                            </div>
                        </a>
                    </div>
                </div>

                <!-- Statistics -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-md-3">
                        <div class="stat-card">
                            <div class="d-flex align-items-center gap-3">
                                <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                                    <i class="fas fa-mobile-alt"></i>
                                </div>
                                <div>
                                    <div class="stat-value"><?php echo number_format($stats['active_trusted_devices']); ?></div>
                                    <div class="stat-label">Active Devices</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card">
                            <div class="d-flex align-items-center gap-3">
                                <div class="stat-icon bg-success bg-opacity-10 text-success">
                                    <i class="fas fa-users"></i>
                                </div>
                                <div>
                                    <div class="stat-value"><?php echo number_format($stats['donors_with_devices']); ?></div>
                                    <div class="stat-label">Donors with Devices</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card">
                            <div class="d-flex align-items-center gap-3">
                                <div class="stat-icon bg-info bg-opacity-10 text-info">
                                    <i class="fas fa-sms"></i>
                                </div>
                                <div>
                                    <div class="stat-value"><?php echo number_format($stats['otp_sent_today']); ?></div>
                                    <div class="stat-label">OTPs Sent Today</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card">
                            <div class="d-flex align-items-center gap-3">
                                <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                                    <i class="fas fa-sign-in-alt"></i>
                                </div>
                                <div>
                                    <div class="stat-value"><?php echo number_format($stats['logins_today']); ?></div>
                                    <div class="stat-label">Logins Today</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tabs -->
                <ul class="nav nav-pills mb-4" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active" data-bs-toggle="tab" href="#recent-logins">
                            <i class="fas fa-history me-1"></i>Recent Logins
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-bs-toggle="tab" href="#trusted-devices">
                            <i class="fas fa-mobile-alt me-1"></i>Trusted Devices
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-bs-toggle="tab" href="#top-donors">
                            <i class="fas fa-chart-bar me-1"></i>Top Donors
                        </a>
                    </li>
                </ul>

                <div class="tab-content">
                    <!-- Recent Logins Tab -->
                    <div class="tab-pane fade show active" id="recent-logins">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-history me-2"></i>Recent Donor Logins
                                </h5>
                            </div>
                            <div class="card-body p-0">
                                <?php if (empty($recent_logins)): ?>
                                <div class="text-center text-muted py-5">
                                    <i class="fas fa-inbox fa-3x opacity-25 mb-3"></i>
                                    <p>No login records yet</p>
                                </div>
                                <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Donor</th>
                                                <th>Method</th>
                                                <th>Time</th>
                                                <th>Details</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recent_logins as $login): 
                                                $details = json_decode($login['after_json'] ?? '{}', true);
                                                $method = $details['method'] ?? 'unknown';
                                            ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo h($login['donor_name'] ?? 'Unknown'); ?></strong>
                                                    <br><small class="text-muted"><?php echo h($login['donor_phone'] ?? ''); ?></small>
                                                </td>
                                                <td>
                                                    <?php if ($method === 'trusted_device'): ?>
                                                    <span class="badge bg-success">
                                                        <i class="fas fa-shield-alt me-1"></i>Trusted Device
                                                    </span>
                                                    <?php elseif ($method === 'sms_otp'): ?>
                                                    <span class="badge bg-info">
                                                        <i class="fas fa-sms me-1"></i>SMS OTP
                                                    </span>
                                                    <?php else: ?>
                                                    <span class="badge bg-secondary"><?php echo h($method); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span title="<?php echo h($login['created_at']); ?>">
                                                        <?php echo date('M j, g:i A', strtotime($login['created_at'])); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if (isset($details['remember'])): ?>
                                                    <small class="text-muted">
                                                        <i class="fas fa-<?php echo $details['remember'] ? 'check' : 'times'; ?>-circle"></i>
                                                        Remember: <?php echo $details['remember'] ? 'Yes' : 'No'; ?>
                                                    </small>
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
                    </div>

                    <!-- Trusted Devices Tab -->
                    <div class="tab-pane fade" id="trusted-devices">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-mobile-alt me-2"></i>Recent Trusted Devices
                                </h5>
                            </div>
                            <div class="card-body p-0">
                                <?php if (empty($recent_devices)): ?>
                                <div class="text-center text-muted py-5">
                                    <i class="fas fa-mobile-alt fa-3x opacity-25 mb-3"></i>
                                    <p>No trusted devices registered yet</p>
                                </div>
                                <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Donor</th>
                                                <th>Device</th>
                                                <th>IP</th>
                                                <th>Last Used</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recent_devices as $device): 
                                                $is_active = $device['is_active'] && strtotime($device['expires_at']) > time();
                                            ?>
                                            <tr class="<?php echo !$is_active ? 'table-secondary' : ''; ?>">
                                                <td>
                                                    <a href="view-donor.php?id=<?php echo $device['donor_id']; ?>">
                                                        <strong><?php echo h($device['donor_name']); ?></strong>
                                                    </a>
                                                    <br><small class="text-muted"><?php echo h($device['donor_phone']); ?></small>
                                                </td>
                                                <td class="table-device-name" title="<?php echo h($device['device_name']); ?>">
                                                    <?php echo h(parseUA($device['device_name'] ?? '')); ?>
                                                </td>
                                                <td>
                                                    <small><?php echo h($device['ip_address'] ?? '-'); ?></small>
                                                </td>
                                                <td>
                                                    <small>
                                                        <?php echo $device['last_used_at'] ? date('M j, g:i A', strtotime($device['last_used_at'])) : 'Never'; ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <?php if ($is_active): ?>
                                                    <span class="badge bg-success">Active</span>
                                                    <?php elseif (!$device['is_active']): ?>
                                                    <span class="badge bg-secondary">Revoked</span>
                                                    <?php else: ?>
                                                    <span class="badge bg-warning">Expired</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($is_active): ?>
                                                    <form method="POST" class="d-inline" onsubmit="return confirm('Revoke this device?');">
                                                        <?php echo csrf_input(); ?>
                                                        <input type="hidden" name="action" value="revoke_device">
                                                        <input type="hidden" name="device_id" value="<?php echo $device['id']; ?>">
                                                        <button type="submit" class="btn btn-outline-danger btn-sm">
                                                            <i class="fas fa-ban"></i>
                                                        </button>
                                                    </form>
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
                    </div>

                    <!-- Top Donors Tab -->
                    <div class="tab-pane fade" id="top-donors">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-chart-bar me-2"></i>Donors by Device Count
                                </h5>
                            </div>
                            <div class="card-body p-0">
                                <?php if (empty($top_donors)): ?>
                                <div class="text-center text-muted py-5">
                                    <i class="fas fa-chart-bar fa-3x opacity-25 mb-3"></i>
                                    <p>No data available</p>
                                </div>
                                <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Donor</th>
                                                <th>Total Devices</th>
                                                <th>Active Devices</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($top_donors as $donor): ?>
                                            <tr>
                                                <td>
                                                    <a href="view-donor.php?id=<?php echo $donor['id']; ?>">
                                                        <strong><?php echo h($donor['name']); ?></strong>
                                                    </a>
                                                    <br><small class="text-muted"><?php echo h($donor['phone']); ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge bg-secondary"><?php echo $donor['device_count']; ?></span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-success"><?php echo $donor['active_count']; ?></span>
                                                </td>
                                                <td>
                                                    <?php if ($donor['active_count'] > 0): ?>
                                                    <form method="POST" class="d-inline" onsubmit="return confirm('Revoke all devices for this donor?');">
                                                        <?php echo csrf_input(); ?>
                                                        <input type="hidden" name="action" value="revoke_all_donor_devices">
                                                        <input type="hidden" name="donor_id" value="<?php echo $donor['id']; ?>">
                                                        <button type="submit" class="btn btn-outline-danger btn-sm">
                                                            <i class="fas fa-ban me-1"></i>Revoke All
                                                        </button>
                                                    </form>
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
                    </div>
                </div>

                <?php endif; ?>

            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
</body>
</html>

