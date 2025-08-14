<?php
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/csrf.php';
require_once __DIR__ . '/../config/db.php';

// Check if logged in and has registrar or admin role
require_login();
$user = current_user();
$role = strtolower(trim((string)($user['role'] ?? '')));
if (!in_array($role, ['registrar', 'admin'], true)) {
    header('Location: ../admin/error/403.php');
    exit;
}

$db = db();
$meId = (int)($user['id'] ?? 0);
$settings = $db->query('SELECT currency_code FROM settings WHERE id=1')->fetch_assoc() ?: [];
$currency = $settings['currency_code'] ?? 'GBP';

// Date filter (single day). Defaults to today. Accepts YYYY-MM-DD.
$day = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date'])
    ? (string)$_GET['date']
    : date('Y-m-d');
// Friendly label for UI
$dayLabel = date('M j, Y', strtotime($day));

// Simple counts for selected day
$counts = ['pledges' => 0, 'payments' => 0, 'combined' => 0];

// Pledges created on selected day by this registrar
$stmt = $db->prepare("SELECT COUNT(*) AS c FROM pledges WHERE created_by_user_id=? AND DATE(created_at)=?");
$stmt->bind_param('is', $meId, $day);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc() ?: [];
$counts['pledges'] = (int)($row['c'] ?? 0);
$stmt->close();

// Payments received on selected day by this registrar
$stmt = $db->prepare("SELECT COUNT(*) AS c FROM payments WHERE received_by_user_id=? AND DATE(received_at)=?");
$stmt->bind_param('is', $meId, $day);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc() ?: [];
$counts['payments'] = (int)($row['c'] ?? 0);
$stmt->close();

$counts['combined'] = $counts['pledges'] + $counts['payments'];

// (Removed hourly trend per request)

// Package breakdown (by amount) for selected day
$pkg = [];
$sql = "SELECT dp.label, SUM(x.amount) s, COUNT(*) c FROM (
  SELECT amount, package_id FROM pledges WHERE created_by_user_id=? AND DATE(created_at)=?
  UNION ALL
  SELECT amount, package_id FROM payments WHERE received_by_user_id=? AND DATE(received_at)=?
) x LEFT JOIN donation_packages dp ON dp.id = x.package_id GROUP BY dp.label ORDER BY s DESC";
$stmt = $db->prepare($sql);
$stmt->bind_param('isis', $meId, $day, $meId, $day);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) { $pkg[] = $r; }
$stmt->close();

// Recent 10 for selected day
$recent = [];
$sql = "SELECT id, 'pledge' AS kind, donor_name, amount, status, created_at AS ts FROM pledges WHERE created_by_user_id=? AND DATE(created_at)=?
        UNION ALL
        SELECT id, 'payment' AS kind, donor_name, amount, status, received_at AS ts FROM payments WHERE received_by_user_id=? AND DATE(received_at)=?
        ORDER BY ts DESC LIMIT 10";
$stmt = $db->prepare($sql);
$stmt->bind_param('isis', $meId, $day, $meId, $day);
$stmt->execute();
$recent = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statistics - Registrar Panel</title>
    <link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/theme.css?v=<?php echo @filemtime(__DIR__ . '/../assets/theme.css'); ?>">
    <link rel="stylesheet" href="assets/registrar.css?v=<?php echo @filemtime(__DIR__ . '/assets/registrar.css'); ?>">
</head>
<body>
    <div class="app-wrapper">
        <?php include 'includes/sidebar.php'; ?>
        
        <div class="app-content">
            <?php include 'includes/topbar.php'; ?>
            
            <main class="main-content">
                <div class="container-fluid">
                    <!-- KPI Cards (single day) -->
                    <div class="row g-3 mb-4">
                        <div class="col-12 col-md-6 col-lg-3">
                            <div class="card h-100">
                                <div class="card-body d-flex align-items-center">
                                    <div class="me-3 text-primary"><i class="fas fa-calendar-day fa-2x"></i></div>
                                    <div>
                                        <div class="text-muted small">Total Submissions (<?php echo htmlspecialchars($dayLabel); ?>)</div>
                                        <div class="fw-bold"><?php echo (int)$counts['combined']; ?> items</div>
                                        <div class="small text-muted">Pledges + Payments</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-lg-3">
                            <div class="card h-100">
                                <div class="card-body d-flex align-items-center">
                                    <div class="me-3 text-warning"><i class="fas fa-handshake fa-2x"></i></div>
                                    <div>
                                        <div class="text-muted small">Pledges Today</div>
                                        <div class="fw-bold"><?php echo (int)$counts['pledges']; ?> items</div>
                                        <div class="small text-muted">Created on <?php echo htmlspecialchars($dayLabel); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-lg-3">
                            <div class="card h-100">
                                <div class="card-body d-flex align-items-center">
                                    <div class="me-3 text-success"><i class="fas fa-money-bill fa-2x"></i></div>
                                    <div>
                                        <div class="text-muted small">Payments Today</div>
                                        <div class="fw-bold"><?php echo (int)$counts['payments']; ?> items</div>
                                        <div class="small text-muted">Received on <?php echo htmlspecialchars($dayLabel); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-lg-3">
                            <div class="card h-100">
                                <div class="card-body d-flex align-items-center">
                                    <div class="me-3 text-info"><i class="fas fa-clipboard-list fa-2x"></i></div>
                                    <div>
                                        <div class="text-muted small">Summary</div>
                                        <div class="fw-bold"><?php echo (int)$counts['combined']; ?> items</div>
                                        <div class="small text-muted">Pledges: <?php echo (int)$counts['pledges']; ?>, Payments: <?php echo (int)$counts['payments']; ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-4">
                        <!-- Package Breakdown -->
                        <div class="col-12 col-lg-4">
                            <div class="card h-100">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0"><i class="fas fa-box me-2"></i>By Package</h5>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($pkg)): ?>
                                        <div class="text-muted small">No data yet.</div>
                                    <?php else: foreach ($pkg as $i => $r): ?>
                                        <div class="d-flex align-items-center mb-2">
                                            <span class="badge bg-light text-dark me-2"><?php echo htmlspecialchars($r['label'] ?? 'Custom/Other'); ?></span>
                                            <div class="ms-auto small text-muted"><?php echo (int)$r['c']; ?> items</div>
                                        </div>
                                        <div class="small mb-3 text-primary fw-semibold"><?php echo $currency . ' ' . number_format((float)$r['s'], 2); ?></div>
                                    <?php endforeach; endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Activity -->
                    <div class="card mt-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="fas fa-clock me-2"></i>Recent Submissions</h5>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($recent)): ?>
                                <div class="p-4 text-center text-muted"><i class="fas fa-inbox me-2"></i>No recent items</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th style="width:44px"></th>
                                                <th>Donor</th>
                                                <th class="text-end">Amount</th>
                                                <th>Status</th>
                                                <th>When</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recent as $r): ?>
                                                <tr>
                                                    <td class="text-center text-muted"><i class="fas <?php echo $r['kind']==='payment'?'fa-money-bill':'fa-handshake';?>"></i></td>
                                                    <td><?php echo htmlspecialchars($r['donor_name'] ?: 'Anonymous'); ?></td>
                                                    <td class="text-end fw-semibold text-primary"><?php echo $currency . ' ' . number_format((float)$r['amount'], 2); ?></td>
                                                    <td><span class="badge <?php echo $r['status']==='approved'?'bg-success':($r['status']==='pending'?'bg-warning text-dark':'bg-secondary');?>"><?php echo htmlspecialchars(ucfirst($r['status'])); ?></span></td>
                                                    <td class="small text-muted"><?php echo $r['ts'] ? date('M j, Y g:i A', strtotime($r['ts'])) : '—'; ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/registrar.js"></script>
</body>
</html>
