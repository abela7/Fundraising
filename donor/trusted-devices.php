<?php
/**
 * Donor Portal - Manage Trusted Devices
 * View and revoke trusted devices for SMS-free login
 */

require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/csrf.php';
require_once __DIR__ . '/../shared/audit_helper.php';
require_once __DIR__ . '/../admin/includes/resilient_db_loader.php';

function current_donor(): ?array {
    if (isset($_SESSION['donor'])) {
        return $_SESSION['donor'];
    }
    return null;
}

function require_donor_login(): void {
    if (!current_donor()) {
        header('Location: login.php');
        exit;
    }
}

require_donor_login();
$donor = current_donor();

$page_title = 'Trusted Devices';
$success_message = '';
$error_message = '';

// Get current device token
$current_token = $_COOKIE['donor_device_token'] ?? null;

// Handle device revocation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db_connection_ok) {
    verify_csrf();
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'revoke_device') {
        $device_id = (int)($_POST['device_id'] ?? 0);
        
        if ($device_id > 0) {
            // Verify device belongs to this donor
            $check = $db->prepare("SELECT id, device_token FROM donor_trusted_devices WHERE id = ? AND donor_id = ?");
            $check->bind_param('ii', $device_id, $donor['id']);
            $check->execute();
            $device = $check->get_result()->fetch_assoc();
            $check->close();
            
            if ($device) {
                // Deactivate the device
                $revoke = $db->prepare("UPDATE donor_trusted_devices SET is_active = 0 WHERE id = ?");
                $revoke->bind_param('i', $device_id);
                $revoke->execute();
                $revoke->close();
                
                // If revoking current device, clear cookie
                if ($device['device_token'] === $current_token) {
                    $cookie_options = [
                        'expires' => time() - 3600,
                        'path' => '/donor/',
                        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
                        'httponly' => true,
                        'samesite' => 'Lax'
                    ];
                    setcookie('donor_device_token', '', $cookie_options);
                    $current_token = null;
                }
                
                // Audit log
                log_audit(
                    $db,
                    'revoke_device',
                    'donor_trusted_device',
                    $device_id,
                    ['device_token' => substr($device['device_token'], 0, 8) . '...'],
                    null,
                    'donor_portal',
                    0
                );
                
                $success_message = 'Device removed successfully.';
            } else {
                $error_message = 'Device not found.';
            }
        }
    } elseif ($action === 'revoke_all') {
        // Revoke all devices except current
        $revoke_all = $db->prepare("UPDATE donor_trusted_devices SET is_active = 0 WHERE donor_id = ? AND device_token != ?");
        $revoke_all->bind_param('is', $donor['id'], $current_token);
        $revoke_all->execute();
        $affected = $revoke_all->affected_rows;
        $revoke_all->close();
        
        // Audit log
        log_audit(
            $db,
            'revoke_all_devices',
            'donor',
            $donor['id'],
            null,
            ['devices_revoked' => $affected],
            'donor_portal',
            0
        );
        
        $success_message = "Removed {$affected} device(s). Your current device remains trusted.";
    }
}

// Fetch trusted devices
$devices = [];
if ($db_connection_ok) {
    $stmt = $db->prepare("
        SELECT id, device_token, device_name, ip_address, last_used_at, created_at, expires_at
        FROM donor_trusted_devices
        WHERE donor_id = ? AND is_active = 1 AND expires_at > NOW()
        ORDER BY last_used_at DESC
    ");
    $stmt->bind_param('i', $donor['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $devices[] = $row;
    }
    $stmt->close();
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
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($donor['preferred_language'] ?? 'en'); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($page_title); ?> - Donor Portal</title>
    <link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/theme.css?v=<?php echo @filemtime(__DIR__ . '/../assets/theme.css'); ?>">
    <link rel="stylesheet" href="assets/donor.css?v=<?php echo @filemtime(__DIR__ . '/assets/donor.css'); ?>">
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
        .device-card.current-device {
            border-color: #10b981;
            background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%);
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
        .current-device .device-icon {
            background: #dcfce7;
            color: #16a34a;
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
        .current-badge {
            background: #dcfce7;
            color: #16a34a;
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.25rem 0.5rem;
            border-radius: 6px;
        }
        .no-devices {
            text-align: center;
            padding: 3rem 1rem;
            color: #64748b;
        }
        .no-devices i {
            font-size: 3rem;
            opacity: 0.3;
            margin-bottom: 1rem;
        }
        .security-tip {
            background: linear-gradient(135deg, #fef3c7 0%, #fef9c3 100%);
            border: 1px solid #fcd34d;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
        .security-tip i {
            color: #d97706;
        }
        .page-header-card {
            background: linear-gradient(135deg, #0a6286 0%, #084d68 100%);
            color: white;
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .page-header-card h1 {
            font-size: 1.5rem;
            margin-bottom: 0.25rem;
        }
        .page-header-card p {
            opacity: 0.9;
            margin: 0;
            font-size: 0.875rem;
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
            .device-actions {
                width: 100%;
                margin-top: 0.75rem;
            }
        }
    </style>
</head>
<body>
<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="app-content">
        <?php include 'includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="container-fluid p-3 p-md-4">
                
                <!-- Page Header -->
                <div class="page-header-card">
                    <div class="d-flex align-items-center gap-3">
                        <div class="d-none d-sm-flex align-items-center justify-content-center" style="width: 50px; height: 50px; background: rgba(255,255,255,0.15); border-radius: 12px;">
                            <i class="fas fa-shield-alt fa-lg"></i>
                        </div>
                        <div>
                            <h1><i class="fas fa-shield-alt d-sm-none me-2"></i>Trusted Devices</h1>
                            <p>Devices that can access your account without SMS verification</p>
                        </div>
                    </div>
                </div>

                <?php if ($success_message): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <!-- Security Tip -->
                <div class="security-tip">
                    <div class="d-flex gap-3">
                        <i class="fas fa-lightbulb fa-lg mt-1"></i>
                        <div>
                            <strong>Security Tip</strong>
                            <p class="mb-0 small">If you notice any devices you don't recognize, remove them immediately and consider changing your phone number with the church office.</p>
                        </div>
                    </div>
                </div>

                <?php if (empty($devices)): ?>
                <!-- No Devices -->
                <div class="no-devices">
                    <i class="fas fa-mobile-alt"></i>
                    <h5>No Trusted Devices</h5>
                    <p class="text-muted">When you log in and choose "Remember this device", it will appear here.</p>
                </div>

                <?php else: ?>
                <!-- Device List -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0">
                        <i class="fas fa-list me-2 text-primary"></i>
                        Your Devices (<?php echo count($devices); ?>)
                    </h5>
                    
                    <?php if (count($devices) > 1): ?>
                    <form method="POST" onsubmit="return confirm('Remove all other devices? Only your current device will remain trusted.');">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="action" value="revoke_all">
                        <button type="submit" class="btn btn-outline-danger btn-sm">
                            <i class="fas fa-trash-alt me-1"></i>Remove All Others
                        </button>
                    </form>
                    <?php endif; ?>
                </div>

                <?php foreach ($devices as $device): 
                    $is_current = ($device['device_token'] === $current_token);
                    $device_info = parseDeviceName($device['device_name'] ?? '');
                ?>
                <div class="device-card <?php echo $is_current ? 'current-device' : ''; ?>">
                    <div class="d-flex flex-wrap align-items-center gap-3">
                        <div class="device-icon">
                            <i class="fas <?php echo $device_info['icon']; ?>"></i>
                        </div>
                        
                        <div class="device-info">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="device-name"><?php echo htmlspecialchars($device_info['display']); ?></span>
                                <?php if ($is_current): ?>
                                <span class="current-badge">
                                    <i class="fas fa-check-circle me-1"></i>This Device
                                </span>
                                <?php endif; ?>
                            </div>
                            <div class="device-meta">
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
                                        <?php echo htmlspecialchars($device['ip_address']); ?>
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
                            <form method="POST" class="d-inline" onsubmit="return confirm('<?php echo $is_current ? 'Remove this device? You will need to verify via SMS on your next login.' : 'Remove this device?'; ?>');">
                                <?php echo csrf_input(); ?>
                                <input type="hidden" name="action" value="revoke_device">
                                <input type="hidden" name="device_id" value="<?php echo $device['id']; ?>">
                                <button type="submit" class="btn <?php echo $is_current ? 'btn-outline-warning' : 'btn-outline-danger'; ?> btn-sm">
                                    <i class="fas fa-times me-1"></i>Remove
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <?php endif; ?>

                <!-- Help Section -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="fas fa-question-circle me-2"></i>How Trusted Devices Work</h6>
                    </div>
                    <div class="card-body">
                        <div class="row g-4">
                            <div class="col-md-4">
                                <div class="d-flex gap-3">
                                    <div class="text-primary">
                                        <i class="fas fa-mobile-alt fa-lg"></i>
                                    </div>
                                    <div>
                                        <strong>What is a trusted device?</strong>
                                        <p class="text-muted small mb-0">A device you've verified with SMS and chosen to remember. You won't need SMS codes when logging in from trusted devices.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="d-flex gap-3">
                                    <div class="text-success">
                                        <i class="fas fa-clock fa-lg"></i>
                                    </div>
                                    <div>
                                        <strong>How long does it last?</strong>
                                        <p class="text-muted small mb-0">Trusted devices are remembered for 90 days. After that, you'll need to verify via SMS again.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="d-flex gap-3">
                                    <div class="text-danger">
                                        <i class="fas fa-shield-alt fa-lg"></i>
                                    </div>
                                    <div>
                                        <strong>When should I remove a device?</strong>
                                        <p class="text-muted small mb-0">Remove devices you no longer use, don't recognize, or if you suspect unauthorized access.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Back Link -->
                <div class="mt-4">
                    <a href="profile.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to Profile
                    </a>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/donor.js"></script>
</body>
</html>

