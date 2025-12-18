<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';

require_admin();

$db = db();

// Check if table exists
$tableExists = $db->query("SHOW TABLES LIKE 'pwa_installations'")->num_rows > 0;

// Get statistics
$stats = [
    'total' => 0,
    'by_user_type' => [],
    'by_device_type' => [],
    'by_browser' => [],
    'recent' => [],
    'monthly' => [],
];

if ($tableExists) {
    // Total
    $stats['total'] = (int) $db->query(
        "SELECT COUNT(*) as c FROM pwa_installations WHERE is_active = 1"
    )->fetch_assoc()['c'];
    
    // By user type
    $byType = $db->query(
        "SELECT user_type, COUNT(*) as count 
         FROM pwa_installations WHERE is_active = 1 
         GROUP BY user_type ORDER BY count DESC"
    );
    while ($row = $byType->fetch_assoc()) {
        $stats['by_user_type'][$row['user_type']] = (int) $row['count'];
    }
    
    // By device type
    $byDevice = $db->query(
        "SELECT device_type, COUNT(*) as count 
         FROM pwa_installations WHERE is_active = 1 
         GROUP BY device_type ORDER BY count DESC"
    );
    while ($row = $byDevice->fetch_assoc()) {
        $stats['by_device_type'][$row['device_type'] ?? 'unknown'] = (int) $row['count'];
    }
    
    // By browser
    $byBrowser = $db->query(
        "SELECT browser, COUNT(*) as count 
         FROM pwa_installations WHERE is_active = 1 
         GROUP BY browser ORDER BY count DESC LIMIT 10"
    );
    while ($row = $byBrowser->fetch_assoc()) {
        $stats['by_browser'][$row['browser'] ?? 'unknown'] = (int) $row['count'];
    }
    
    // Recent installations
    $recent = $db->query(
        "SELECT p.*, 
                CASE 
                    WHEN p.user_type = 'donor' THEN d.name 
                    ELSE u.name 
                END as user_name
         FROM pwa_installations p
         LEFT JOIN donors d ON p.user_type = 'donor' AND p.user_id = d.id
         LEFT JOIN users u ON p.user_type != 'donor' AND p.user_id = u.id
         WHERE p.is_active = 1
         ORDER BY p.installed_at DESC
         LIMIT 20"
    );
    while ($row = $recent->fetch_assoc()) {
        $stats['recent'][] = $row;
    }
    
    // Monthly trend
    $monthly = $db->query(
        "SELECT DATE_FORMAT(installed_at, '%Y-%m') as month, COUNT(*) as count
         FROM pwa_installations WHERE is_active = 1
         GROUP BY DATE_FORMAT(installed_at, '%Y-%m')
         ORDER BY month DESC LIMIT 12"
    );
    while ($row = $monthly->fetch_assoc()) {
        $stats['monthly'][$row['month']] = (int) $row['count'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PWA Analytics - Admin</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../../assets/theme.css">
    <style>
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
            padding: 1.5rem;
        }
        .stat-card.donor { background: linear-gradient(135deg, #0a6286 0%, #084a66 100%); }
        .stat-card.registrar { background: linear-gradient(135deg, #28a745 0%, #1e7b34 100%); }
        .stat-card.admin { background: linear-gradient(135deg, #6f42c1 0%, #5a32a3 100%); }
        .stat-number { font-size: 2.5rem; font-weight: 700; }
        .stat-label { opacity: 0.85; }
        .device-icon { font-size: 1.5rem; margin-right: 0.5rem; }
        .chart-container { height: 200px; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <?php include __DIR__ . '/../includes/topbar.php'; ?>
    
    <div class="container-fluid py-4">
        <div class="row mb-4">
            <div class="col">
                <h1><i class="fas fa-mobile-alt me-2"></i>PWA Installation Analytics</h1>
                <p class="text-muted">Track app installations across donor, registrar, and admin PWAs</p>
            </div>
        </div>
        
        <?php if (!$tableExists): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>Database table not found.</strong> Please run the migration to create the <code>pwa_installations</code> table.
            <pre class="mt-2 mb-0"><code>mysql -u root fundraising_db < database/pwa_installations.sql</code></pre>
        </div>
        <?php else: ?>
        
        <!-- Overview Cards -->
        <div class="row g-4 mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-number"><?= $stats['total'] ?></div>
                    <div class="stat-label">Total Installations</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card donor">
                    <div class="stat-number"><?= $stats['by_user_type']['donor'] ?? 0 ?></div>
                    <div class="stat-label">Donor App</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card registrar">
                    <div class="stat-number"><?= $stats['by_user_type']['registrar'] ?? 0 ?></div>
                    <div class="stat-label">Registrar App</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card admin">
                    <div class="stat-number"><?= $stats['by_user_type']['admin'] ?? 0 ?></div>
                    <div class="stat-label">Admin App</div>
                </div>
            </div>
        </div>
        
        <div class="row g-4 mb-4">
            <!-- By Device Type -->
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-devices me-2"></i>By Device Type</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($stats['by_device_type'])): ?>
                            <p class="text-muted">No data yet</p>
                        <?php else: ?>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($stats['by_device_type'] as $device => $count): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span>
                                        <?php 
                                        $icon = match($device) {
                                            'android' => '🤖',
                                            'ios' => '🍎',
                                            'desktop' => '💻',
                                            default => '📱'
                                        };
                                        echo $icon . ' ' . ucfirst($device);
                                        ?>
                                    </span>
                                    <span class="badge bg-primary rounded-pill"><?= $count ?></span>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- By Browser -->
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-globe me-2"></i>By Browser</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($stats['by_browser'])): ?>
                            <p class="text-muted">No data yet</p>
                        <?php else: ?>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($stats['by_browser'] as $browser => $count): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><?= htmlspecialchars($browser) ?></span>
                                    <span class="badge bg-secondary rounded-pill"><?= $count ?></span>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Recent Installations -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-history me-2"></i>Recent Installations</h5>
            </div>
            <div class="card-body">
                <?php if (empty($stats['recent'])): ?>
                    <p class="text-muted">No installations yet</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Type</th>
                                <th>Device</th>
                                <th>Browser</th>
                                <th>Screen</th>
                                <th>Installed</th>
                                <th>Last Opened</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats['recent'] as $install): ?>
                            <tr>
                                <td><?= htmlspecialchars($install['user_name'] ?? 'Unknown') ?></td>
                                <td>
                                    <span class="badge bg-<?= match($install['user_type']) {
                                        'donor' => 'info',
                                        'registrar' => 'success',
                                        'admin' => 'purple',
                                        default => 'secondary'
                                    } ?>">
                                        <?= ucfirst($install['user_type']) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($install['device_platform'] ?? $install['device_type'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($install['browser'] ?? '-') ?></td>
                                <td><?= $install['screen_width'] ? "{$install['screen_width']}×{$install['screen_height']}" : '-' ?></td>
                                <td><?= $install['installed_at'] ? date('M j, Y H:i', strtotime($install['installed_at'])) : '-' ?></td>
                                <td><?= $install['last_opened_at'] ? date('M j, Y H:i', strtotime($install['last_opened_at'])) : '-' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php endif; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

