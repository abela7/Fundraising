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
$settings = $db->query('SELECT currency_code FROM settings WHERE id=1')->fetch_assoc() ?: [];
$currency = $settings['currency_code'] ?? 'GBP';
$meId = (int)($user['id'] ?? 0);

// Filters
$typeParam = isset($_GET['type']) ? (string)$_GET['type'] : 'all';
$type = in_array($typeParam, ['all','pledge','payment'], true) ? $typeParam : 'all';
$statusParam = isset($_GET['status']) ? (string)$_GET['status'] : 'all';
$status = in_array($statusParam, ['all','pending','approved','voided'], true) ? $statusParam : 'all';
$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 12;
$offset = ($page - 1) * $perPage;

// Build dynamic WHERE fragments and params for each subquery
$wherePledge = ['pl.created_by_user_id = ?'];
$paramsPledge = ['i', $meId];
if ($status !== 'all') { $wherePledge[] = 'pl.status = ?'; $paramsPledge[0] .= 's'; $paramsPledge[] = $status; }
if ($q !== '') {
    $like = "%$q%";
    $wherePledge[] = '(pl.donor_name LIKE ? OR pl.donor_phone LIKE ? OR pl.donor_email LIKE ? OR pl.notes LIKE ?)';
    $paramsPledge[0] .= 'ssss';
    array_push($paramsPledge, $like, $like, $like, $like);
}

$wherePayment = ['pm.received_by_user_id = ?'];
$paramsPayment = ['i', $meId];
if ($status !== 'all') { $wherePayment[] = 'pm.status = ?'; $paramsPayment[0] .= 's'; $paramsPayment[] = $status; }
if ($q !== '') {
    $like = "%$q%";
    $wherePayment[] = '(pm.donor_name LIKE ? OR pm.donor_phone LIKE ? OR pm.donor_email LIKE ? OR pm.reference LIKE ?)';
    $paramsPayment[0] .= 'ssss';
    array_push($paramsPayment, $like, $like, $like, $like);
}

// Base subqueries
$sqlPledges = "
    SELECT 
        pl.id AS id,
        'pledge' AS kind,
        pl.donor_name, pl.donor_phone, pl.donor_email,
        pl.amount, pl.status,
        pl.created_at AS ts,
        dp.label AS package_label
    FROM pledges pl
    LEFT JOIN donation_packages dp ON dp.id = pl.package_id
    WHERE " . implode(' AND ', $wherePledge) . "
";

$sqlPayments = "
    SELECT 
        pm.id AS id,
        'payment' AS kind,
        pm.donor_name, pm.donor_phone, pm.donor_email,
        pm.amount, pm.status,
        pm.received_at AS ts,
        dp.label AS package_label
    FROM payments pm
    LEFT JOIN donation_packages dp ON dp.id = pm.package_id
    WHERE " . implode(' AND ', $wherePayment) . "
";

// Apply type filter by excluding a subquery
$unionParts = [];
$bindTypes = '';
$bindValues = [];
if ($type === 'pledge' || $type === 'all') {
    $unionParts[] = $sqlPledges;
    $bindTypes .= $paramsPledge[0];
    $bindValues = array_merge($bindValues, array_slice($paramsPledge, 1));
}
if ($type === 'payment' || $type === 'all') {
    $unionParts[] = $sqlPayments;
    $bindTypes .= $paramsPayment[0];
    $bindValues = array_merge($bindValues, array_slice($paramsPayment, 1));
}

$unionSql = implode("\nUNION ALL\n", $unionParts);
if ($unionSql === '') {
    // No parts due to type filter - make an empty result set safely
    $unionSql = "SELECT 0 AS id, '' AS kind, '' AS donor_name, '' AS donor_phone, '' AS donor_email, 0 AS amount, '' AS status, NOW() AS ts, '' AS package_label WHERE 1=0";
    $bindTypes = '';
    $bindValues = [];
}

// Count total
$countSql = "SELECT COUNT(*) AS c FROM (" . $unionSql . ") t";
$stmt = $db->prepare($countSql);
if ($bindTypes !== '') { $stmt->bind_param($bindTypes, ...$bindValues); }
$stmt->execute();
$totalRows = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

// Fetch paginated
$dataSql = $unionSql . "\nORDER BY ts DESC\nLIMIT ? OFFSET ?";
$stmt = $db->prepare($dataSql);
if ($bindTypes !== '') {
    $bindTypes2 = $bindTypes . 'ii';
    $stmt->bind_param($bindTypes2, ...array_merge($bindValues, [$perPage, $offset]));
} else {
    $stmt->bind_param('ii', $perPage, $offset);
}
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function badgeClass(string $status): string {
    switch ($status) {
        case 'approved': return 'bg-success';
        case 'pending': return 'bg-warning text-dark';
        case 'voided': return 'bg-danger';
        default: return 'bg-secondary';
    }
}
function kindIcon(string $k): string { return $k === 'payment' ? 'fa-money-bill' : 'fa-handshake'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Registrations - Registrar Panel</title>
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
                    <div class="card mb-3">
                        <div class="card-body">
                            <form class="row g-2 align-items-end" method="get">
                                <div class="col-12 col-md-4">
                                    <label class="form-label">Search</label>
                                    <input type="text" name="q" class="form-control" value="<?php echo h($q); ?>" placeholder="Name, phone, email, notes...">
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label">Type</label>
                                    <select name="type" class="form-select">
                                        <option value="all" <?php echo $type==='all'?'selected':''; ?>>All</option>
                                        <option value="pledge" <?php echo $type==='pledge'?'selected':''; ?>>Pledges</option>
                                        <option value="payment" <?php echo $type==='payment'?'selected':''; ?>>Payments</option>
                                    </select>
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label">Status</label>
                                    <select name="status" class="form-select">
                                        <option value="all" <?php echo $status==='all'?'selected':''; ?>>All</option>
                                        <option value="pending" <?php echo $status==='pending'?'selected':''; ?>>Pending</option>
                                        <option value="approved" <?php echo $status==='approved'?'selected':''; ?>>Approved</option>
                                        <option value="voided" <?php echo $status==='voided'?'selected':''; ?>>Voided</option>
                                    </select>
                                </div>
                                <div class="col-12 col-md-2 d-grid">
                                    <button class="btn btn-primary"><i class="fas fa-filter me-1"></i>Filter</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">My Registrations</h5>
                            <span class="text-muted small">Total: <?php echo number_format($totalRows); ?></span>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($rows)): ?>
                                <div class="p-4 text-center text-muted">
                                    <i class="fas fa-inbox fa-2x mb-2"></i>
                                    <div>No records found.</div>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th style="width:44px"></th>
                                                <th>Donor</th>
                                                <th>Package</th>
                                                <th class="text-end">Amount</th>
                                                <th>Status</th>
                                                <th>When</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($rows as $r): ?>
                                                <tr>
                                                    <td class="text-center text-muted">
                                                        <i class="fas <?php echo kindIcon($r['kind']); ?>"></i>
                                                    </td>
                                                    <td>
                                                        <div class="fw-semibold"><?php echo h($r['donor_name'] ?: 'Anonymous'); ?></div>
                                                        <div class="small text-muted"><?php echo h($r['donor_phone'] ?: ($r['donor_email'] ?: '—')); ?></div>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-light text-dark"><?php echo h($r['package_label'] ?: 'Custom/Other'); ?></span>
                                                    </td>
                                                    <td class="text-end fw-semibold text-primary">
                                                        <?php echo $currency . ' ' . number_format((float)$r['amount'], 2); ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge <?php echo badgeClass((string)$r['status']); ?>"><?php echo h(ucfirst((string)$r['status'])); ?></span>
                                                    </td>
                                                    <td>
                                                        <div class="small text-muted"><?php echo date('M j, Y g:i A', strtotime((string)$r['ts'])); ?></div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if ($totalPages > 1): ?>
                        <div class="card-footer d-flex justify-content-between align-items-center">
                            <?php 
                                $baseParams = $_GET; unset($baseParams['page']);
                                $qs = function($p) use ($baseParams) { return http_build_query(array_merge($baseParams, ['page'=>$p])); };
                            ?>
                            <div class="text-muted small">Page <?php echo $page; ?> of <?php echo $totalPages; ?></div>
                            <nav>
                                <ul class="pagination mb-0">
                                    <li class="page-item <?php echo $page<=1?'disabled':''; ?>">
                                        <a class="page-link" href="?<?php echo $qs(max(1,$page-1)); ?>">&laquo;</a>
                                    </li>
                                    <?php 
                                        $window = 3;
                                        $start = max(1, $page - $window);
                                        $end = min($totalPages, $page + $window);
                                        for ($p=$start; $p<=$end; $p++):
                                    ?>
                                        <li class="page-item <?php echo $p===$page?'active':''; ?>">
                                            <a class="page-link" href="?<?php echo $qs($p); ?>"><?php echo $p; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    <li class="page-item <?php echo $page>=$totalPages?'disabled':''; ?>">
                                        <a class="page-link" href="?<?php echo $qs(min($totalPages,$page+1)); ?>">&raquo;</a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/registrar.js"></script>
</body>
</html>
