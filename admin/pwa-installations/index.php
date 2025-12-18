<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

$user = current_user();
if (($user['role'] ?? '') !== 'admin') {
    header('Location: ../error/403.php');
    exit;
}

$db = db();
$page_title = 'PWA Installations';

// Get filter
$filterType = $_GET['type'] ?? 'all';
$filterDevice = $_GET['device'] ?? 'all';

// Build query
$where = ['1=1'];
$params = [];
$types = '';

if ($filterType !== 'all') {
    $where[] = 'p.user_type = ?';
    $params[] = $filterType;
    $types .= 's';
}

if ($filterDevice !== 'all') {
    $where[] = 'p.device_type = ?';
    $params[] = $filterDevice;
    $types .= 's';
}

$whereClause = implode(' AND ', $where);

// Get stats
$stats = [
    'total' => 0,
    'active' => 0,
    'ios' => 0,
    'android' => 0,
    'desktop' => 0,
    'today' => 0,
    'this_week' => 0
];

try {
    // Check if table exists
    $tableCheck = $db->query("SHOW TABLES LIKE 'pwa_installations'");
    $tableExists = $tableCheck && $tableCheck->num_rows > 0;
    
    if ($tableExists) {
        $statsQuery = $db->query("SELECT 
            COUNT(*) as total,
            SUM(is_active) as active,
            SUM(CASE WHEN device_type = 'ios' THEN 1 ELSE 0 END) as ios,
            SUM(CASE WHEN device_type = 'android' THEN 1 ELSE 0 END) as android,
            SUM(CASE WHEN device_type = 'desktop' THEN 1 ELSE 0 END) as desktop,
            SUM(CASE WHEN DATE(installed_at) = CURDATE() THEN 1 ELSE 0 END) as today,
            SUM(CASE WHEN installed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as this_week
            FROM pwa_installations");
        
        if ($statsQuery) {
            $stats = $statsQuery->fetch_assoc() ?: $stats;
        }
    }
} catch (Exception $e) {
    // Table doesn't exist yet
    $tableExists = false;
}

// Get installations list
$installations = [];
if ($tableExists) {
    $sql = "SELECT p.*, 
            CASE 
                WHEN p.user_type = 'donor' THEN d.name 
                ELSE u.name 
            END as user_name,
            CASE 
                WHEN p.user_type = 'donor' THEN d.phone 
                ELSE u.phone 
            END as user_phone
            FROM pwa_installations p
            LEFT JOIN donors d ON p.user_type = 'donor' AND p.user_id = d.id
            LEFT JOIN users u ON p.user_type != 'donor' AND p.user_id = u.id
            WHERE $whereClause
            ORDER BY p.installed_at DESC
            LIMIT 100";
    
    if (!empty($params)) {
        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $installations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } else {
        $result = $db->query($sql);
        $installations = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo $page_title; ?> - Admin</title>
  <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="../assets/admin.css">
  <style>
    .stat-card {
      background: white;
      border-radius: 12px;
      padding: 1.25rem;
      box-shadow: 0 2px 8px rgba(0,0,0,0.08);
      text-align: center;
    }
    .stat-value {
      font-size: 2rem;
      font-weight: 700;
      color: #0a6286;
    }
    .stat-label {
      font-size: 0.8rem;
      color: #6c757d;
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }
    .stat-card.ios { border-left: 4px solid #007AFF; }
    .stat-card.android { border-left: 4px solid #3DDC84; }
    .stat-card.desktop { border-left: 4px solid #6c757d; }
    .device-badge {
      display: inline-flex;
      align-items: center;
      gap: 0.25rem;
      padding: 0.25rem 0.5rem;
      border-radius: 20px;
      font-size: 0.75rem;
      font-weight: 500;
    }
    .device-ios { background: #e3f2fd; color: #007AFF; }
    .device-android { background: #e8f5e9; color: #2e7d32; }
    .device-desktop { background: #f5f5f5; color: #616161; }
    .device-unknown { background: #fff3e0; color: #ef6c00; }
    .user-type-badge {
      padding: 0.25rem 0.5rem;
      border-radius: 4px;
      font-size: 0.7rem;
      font-weight: 600;
      text-transform: uppercase;
    }
    .type-admin { background: #f3e5f5; color: #7b1fa2; }
    .type-registrar { background: #e8f5e9; color: #2e7d32; }
    .type-donor { background: #e3f2fd; color: #1565c0; }
    .status-active { color: #28a745; }
    .status-inactive { color: #dc3545; }
    .last-seen {
      font-size: 0.8rem;
      color: #6c757d;
    }
    .empty-state {
      text-align: center;
      padding: 3rem;
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
  <?php include '../includes/sidebar.php'; ?>
  
  <div class="admin-content">
    <?php include '../includes/topbar.php'; ?>
    
    <main class="main-content p-4">
      <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0">
          <i class="fas fa-mobile-alt me-2 text-primary"></i>
          PWA Installations
        </h2>
        <div class="text-muted">
          <i class="fas fa-info-circle"></i>
          Track app installations across devices
        </div>
      </div>
      
      <?php if (!$tableExists): ?>
        <div class="alert alert-warning">
          <i class="fas fa-exclamation-triangle me-2"></i>
          <strong>Table not found.</strong> 
          Run the SQL from <code>database/pwa_installations.sql</code> in phpMyAdmin to create the tracking table.
        </div>
      <?php else: ?>
      
      <!-- Stats Grid -->
      <div class="row g-3 mb-4">
        <div class="col-6 col-md-4 col-lg-2">
          <div class="stat-card">
            <div class="stat-value"><?php echo number_format((int)($stats['total'] ?? 0)); ?></div>
            <div class="stat-label">Total Installs</div>
          </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
          <div class="stat-card">
            <div class="stat-value"><?php echo number_format((int)($stats['active'] ?? 0)); ?></div>
            <div class="stat-label">Active</div>
          </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
          <div class="stat-card ios">
            <div class="stat-value"><?php echo number_format((int)($stats['ios'] ?? 0)); ?></div>
            <div class="stat-label"><i class="fab fa-apple"></i> iOS</div>
          </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
          <div class="stat-card android">
            <div class="stat-value"><?php echo number_format((int)($stats['android'] ?? 0)); ?></div>
            <div class="stat-label"><i class="fab fa-android"></i> Android</div>
          </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
          <div class="stat-card desktop">
            <div class="stat-value"><?php echo number_format((int)($stats['desktop'] ?? 0)); ?></div>
            <div class="stat-label"><i class="fas fa-desktop"></i> Desktop</div>
          </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
          <div class="stat-card">
            <div class="stat-value"><?php echo number_format((int)($stats['today'] ?? 0)); ?></div>
            <div class="stat-label">Today</div>
          </div>
        </div>
      </div>
      
      <!-- Filters -->
      <div class="card mb-4">
        <div class="card-body py-2">
          <form method="get" class="row g-2 align-items-center">
            <div class="col-auto">
              <label class="form-label mb-0 me-2">User Type:</label>
              <select name="type" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="all" <?php echo $filterType === 'all' ? 'selected' : ''; ?>>All</option>
                <option value="donor" <?php echo $filterType === 'donor' ? 'selected' : ''; ?>>Donors</option>
                <option value="registrar" <?php echo $filterType === 'registrar' ? 'selected' : ''; ?>>Registrars</option>
                <option value="admin" <?php echo $filterType === 'admin' ? 'selected' : ''; ?>>Admins</option>
              </select>
            </div>
            <div class="col-auto">
              <label class="form-label mb-0 me-2">Device:</label>
              <select name="device" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="all" <?php echo $filterDevice === 'all' ? 'selected' : ''; ?>>All</option>
                <option value="ios" <?php echo $filterDevice === 'ios' ? 'selected' : ''; ?>>iOS</option>
                <option value="android" <?php echo $filterDevice === 'android' ? 'selected' : ''; ?>>Android</option>
                <option value="desktop" <?php echo $filterDevice === 'desktop' ? 'selected' : ''; ?>>Desktop</option>
              </select>
            </div>
            <?php if ($filterType !== 'all' || $filterDevice !== 'all'): ?>
              <div class="col-auto">
                <a href="?" class="btn btn-sm btn-outline-secondary">Clear Filters</a>
              </div>
            <?php endif; ?>
          </form>
        </div>
      </div>
      
      <!-- Installations Table -->
      <div class="card">
        <div class="card-header bg-white">
          <strong>Recent Installations</strong>
          <span class="text-muted">(<?php echo count($installations); ?> shown)</span>
        </div>
        <div class="card-body p-0">
          <?php if (empty($installations)): ?>
            <div class="empty-state">
              <i class="fas fa-mobile-alt"></i>
              <h5>No installations yet</h5>
              <p>When users install the app, they'll appear here.</p>
            </div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-hover mb-0">
                <thead class="table-light">
                  <tr>
                    <th>User</th>
                    <th>Type</th>
                    <th>Device</th>
                    <th>Browser</th>
                    <th>Installed</th>
                    <th>Last Seen</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($installations as $i): ?>
                    <tr>
                      <td>
                        <strong><?php echo htmlspecialchars($i['user_name'] ?? 'Unknown'); ?></strong>
                        <?php if (!empty($i['user_phone'])): ?>
                          <br><small class="text-muted"><?php echo htmlspecialchars($i['user_phone']); ?></small>
                        <?php endif; ?>
                      </td>
                      <td>
                        <span class="user-type-badge type-<?php echo $i['user_type']; ?>">
                          <?php echo ucfirst($i['user_type']); ?>
                        </span>
                      </td>
                      <td>
                        <?php
                          $deviceClass = 'unknown';
                          $deviceIcon = 'fas fa-question';
                          if ($i['device_type'] === 'ios') { $deviceClass = 'ios'; $deviceIcon = 'fab fa-apple'; }
                          elseif ($i['device_type'] === 'android') { $deviceClass = 'android'; $deviceIcon = 'fab fa-android'; }
                          elseif ($i['device_type'] === 'desktop') { $deviceClass = 'desktop'; $deviceIcon = 'fas fa-desktop'; }
                        ?>
                        <span class="device-badge device-<?php echo $deviceClass; ?>">
                          <i class="<?php echo $deviceIcon; ?>"></i>
                          <?php echo htmlspecialchars($i['device_platform'] ?? 'Unknown'); ?>
                        </span>
                      </td>
                      <td><?php echo htmlspecialchars($i['browser'] ?? '-'); ?></td>
                      <td>
                        <?php echo date('M j, Y', strtotime($i['installed_at'])); ?>
                        <br><small class="text-muted"><?php echo date('g:i A', strtotime($i['installed_at'])); ?></small>
                      </td>
                      <td>
                        <?php if (!empty($i['last_opened_at'])): ?>
                          <?php 
                            $lastSeen = strtotime($i['last_opened_at']);
                            $diff = time() - $lastSeen;
                            if ($diff < 3600) $ago = round($diff / 60) . 'm ago';
                            elseif ($diff < 86400) $ago = round($diff / 3600) . 'h ago';
                            else $ago = round($diff / 86400) . 'd ago';
                          ?>
                          <span class="last-seen"><?php echo $ago; ?></span>
                        <?php else: ?>
                          <span class="text-muted">-</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php if ($i['is_active']): ?>
                          <i class="fas fa-check-circle status-active"></i>
                        <?php else: ?>
                          <i class="fas fa-times-circle status-inactive"></i>
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
      
      <?php endif; ?>
    </main>
  </div>
</div>

<?php include '../includes/fab.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
</body>
</html>

