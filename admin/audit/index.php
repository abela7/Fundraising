<?php
require_once '../../config/db.php';
require_once '../../shared/auth.php';
require_login();
require_admin();

$db = db();
$current_user = current_user();

// Helpers
function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function ip_from_binary(?string $bin): string {
  if ($bin === null) return '-';
  $s = @inet_ntop($bin);
  return $s ?: '-';
}

// Filters (action, user, date range, q)
$action  = trim((string)($_GET['action'] ?? ''));
$userId  = (int)($_GET['user'] ?? 0);
$dateFrom= trim((string)($_GET['from'] ?? ''));
$dateTo  = trim((string)($_GET['to'] ?? ''));
$q       = trim((string)($_GET['q'] ?? ''));

$wheres = [];$params=[];$types='';
if ($action !== '') { $wheres[] = 'al.action = ?'; $params[] = $action; $types.='s'; }
if ($userId > 0)   { $wheres[] = 'al.user_id = ?'; $params[] = $userId; $types.='i'; }
if ($dateFrom !== '') { $wheres[] = 'al.created_at >= ?'; $params[] = $dateFrom.' 00:00:00'; $types.='s'; }
if ($dateTo   !== '') { $wheres[] = 'al.created_at <= ?'; $params[] = $dateTo.' 23:59:59'; $types.='s'; }
if ($q !== '') {
  $wheres[] = '(
    al.entity_type LIKE CONCAT("%", ?, "%") OR
    al.action      LIKE CONCAT("%", ?, "%") OR
    CAST(al.before_json AS CHAR) LIKE CONCAT("%", ?, "%") OR
    CAST(al.after_json  AS CHAR) LIKE CONCAT("%", ?, "%")
  )';
  for ($i=0;$i<4;$i++){ $params[]=$q; $types.='s'; }
}
$whereSql = $wheres ? ('WHERE '.implode(' AND ',$wheres)) : '';

// Export CSV
if (isset($_GET['export']) && $_GET['export']==='csv') {
  header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename="audit_logs.csv"');
  $sql = "SELECT al.*, u.name AS user_name, u.role AS user_role FROM audit_logs al LEFT JOIN users u ON u.id=al.user_id $whereSql ORDER BY al.created_at DESC";
  $st = $db->prepare($sql); if ($types!=='') $st->bind_param($types, ...$params); $st->execute(); $res=$st->get_result();
  $out=fopen('php://output','w'); fputcsv($out,['Time','User','Role','Action','Entity','IP','Source','Before','After']);
  while($r=$res->fetch_assoc()){
    fputcsv($out,[ $r['created_at'], $r['user_name'], $r['user_role'], $r['action'], $r['entity_type'].' #'.$r['entity_id'], ip_from_binary($r['ip_address']), $r['source'], $r['before_json'], $r['after_json'] ]);
  }
  fclose($out); exit;
}

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25; $offset = ($page-1)*$perPage;
$cnt = $db->prepare("SELECT COUNT(*) AS c FROM audit_logs al $whereSql"); if ($types!=='') $cnt->bind_param($types, ...$params); $cnt->execute(); $totalRows=(int)($cnt->get_result()->fetch_assoc()['c'] ?? 0); $totalPages = (int)ceil($totalRows/$perPage);

// Fetch logs
$sql = "SELECT al.*, u.name AS user_name, u.role AS user_role
        FROM audit_logs al
        LEFT JOIN users u ON u.id = al.user_id
        $whereSql
        ORDER BY al.created_at DESC
        LIMIT ? OFFSET ?";
$stmt = $db->prepare($sql);
if ($types!=='') {
  $bindTypes = $types.'ii'; $bindParams = array_merge($params, [$perPage, $offset]);
  $stmt->bind_param($bindTypes, ...$bindParams);
} else {
  $stmt->bind_param('ii', $perPage, $offset);
}
$stmt->execute();
$logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Users for dropdown
$users = $db->query("SELECT id, name, role FROM users WHERE active=1 ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC) ?? [];

// Quick stats (today)
$today = date('Y-m-d');
$statTotal = $db->query("SELECT COUNT(*) AS c FROM audit_logs")->fetch_assoc()['c'] ?? 0;
$statApproves = $db->query("SELECT COUNT(*) AS c FROM audit_logs WHERE action='approve' AND DATE(created_at)=CURDATE()")->fetch_assoc()['c'] ?? 0;
$statLogins = $db->query("SELECT COUNT(*) AS c FROM audit_logs WHERE action='login' AND DATE(created_at)=CURDATE()")->fetch_assoc()['c'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Logs - Fundraising System</title>
    
    <!-- CSS Dependencies -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="../../assets/theme.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <link rel="stylesheet" href="assets/audit.css">
    
    <!-- Favicon -->
    <link rel="icon" href="../../assets/favicon.svg" type="image/svg+xml">
</head>
<body>
    <div class="admin-wrapper">
        <?php include '../includes/sidebar.php'; ?>
        <div class="admin-content">
            <?php include '../includes/topbar.php'; ?>
            <main class="main-content">
                <div class="container-fluid">
                    <!-- Page Header (actions only) -->
                    <div class="d-flex justify-content-end mb-4">
                        <div class="d-flex gap-2">
                            <a class="btn btn-outline-success" href="?export=csv&<?php echo http_build_query($_GET); ?>">
                                    <i class="fas fa-download me-2"></i>Export CSV
                                </a>
                            <button class="btn btn-outline-primary" onclick="refreshData()">
                                <i class="fas fa-sync-alt me-2"></i>Refresh
                            </button>
                        </div>
                    </div>

                    <!-- Modern Stats Cards -->
                    <div class="row g-3 mb-4">
                        <div class="col-xl-3 col-md-6">
                            <div class="card border-0 shadow-sm h-100">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0">
                                            <div class="icon-circle bg-primary">
                                                <i class="fas fa-history text-white"></i>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1 ms-3">
                                            <div class="small fw-bold text-primary mb-1">Total Events</div>
                                            <div class="h5 mb-0"><?php echo number_format((int)$statTotal); ?></div>
                                            <div class="small text-muted">All time</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-3 col-md-6">
                            <div class="card border-0 shadow-sm h-100">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0">
                                            <div class="icon-circle bg-success">
                                                <i class="fas fa-check-circle text-white"></i>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1 ms-3">
                                            <div class="small fw-bold text-success mb-1">Approvals Today</div>
                                            <div class="h5 mb-0"><?php echo number_format((int)$statApproves); ?></div>
                                            <div class="small text-muted">Admin actions</div>
                          </div>
                        </div>
                      </div>
                            </div>
                        </div>
                        <div class="col-xl-3 col-md-6">
                            <div class="card border-0 shadow-sm h-100">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0">
                                            <div class="icon-circle bg-info">
                                                <i class="fas fa-sign-in-alt text-white"></i>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1 ms-3">
                                            <div class="small fw-bold text-info mb-1">Logins Today</div>
                                            <div class="h5 mb-0"><?php echo number_format((int)$statLogins); ?></div>
                                            <div class="small text-muted">User sessions</div>
                          </div>
                        </div>
                      </div>
                            </div>
                        </div>
                        <div class="col-xl-3 col-md-6">
                            <div class="card border-0 shadow-sm h-100">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0">
                                            <div class="icon-circle bg-warning">
                                                <i class="fas fa-filter text-white"></i>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1 ms-3">
                                            <div class="small fw-bold text-warning mb-1">Filtered Results</div>
                                            <div class="h5 mb-0"><?php echo number_format($totalRows); ?></div>
                                            <div class="small text-muted">Current view</div>
                                        </div>
                                    </div>
                          </div>
                        </div>
                      </div>
                    </div>
                    
                    <!-- Modern Filters -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-transparent border-0">
                            <h6 class="mb-0">
                                <i class="fas fa-filter me-2 text-primary"></i>
                                Filters & Search
                            </h6>
                        </div>
                        <div class="card-body">
                            <form id="auditFilterForm" method="get" class="filter-form">
                                <div class="row g-3">
                                    <div class="col-lg-3 col-md-6">
                                        <label class="form-label fw-bold">Action Type</label>
                                        <select class="form-select" name="action" id="actionFilter" onchange="document.getElementById('auditFilterForm').submit();">
                                            <option value="" <?php echo $action===''?'selected':''; ?>>All Actions</option>
                                            <?php 
                                            $actionIcons = [
                                                'login' => 'fas fa-sign-in-alt',
                                                'logout' => 'fas fa-sign-out-alt', 
                                                'create' => 'fas fa-plus',
                                                'create_pending' => 'fas fa-plus-circle',
                                                'update' => 'fas fa-edit',
                                                'delete' => 'fas fa-trash',
                                                'approve' => 'fas fa-check',
                                                'reject' => 'fas fa-times',
                                                'undo' => 'fas fa-undo'
                                            ];
                                            foreach (['login','logout','create','create_pending','update','delete','approve','reject','undo'] as $opt): ?>
                                              <option value="<?php echo $opt; ?>" <?php echo $action===$opt?'selected':''; ?>><?php echo ucfirst(str_replace('_', ' ', $opt)); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-lg-3 col-md-6">
                                        <label class="form-label fw-bold">User</label>
                                        <select class="form-select" name="user" id="userFilter" onchange="document.getElementById('auditFilterForm').submit();">
                                            <option value="0" <?php echo $userId===0?'selected':''; ?>>All Users</option>
                                            <?php foreach($users as $u): ?>
                                              <option value="<?php echo (int)$u['id']; ?>" <?php echo $userId===(int)$u['id']?'selected':''; ?>><?php echo h($u['name']).' ('.h($u['role']).')'; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-lg-2 col-md-6">
                                        <label class="form-label fw-bold">From Date</label>
                                        <input type="date" class="form-control" name="from" id="dateFrom" value="<?php echo h($dateFrom ?: date('Y-m-d', strtotime('-7 days'))); ?>" onchange="document.getElementById('auditFilterForm').submit();">
                                    </div>
                                    <div class="col-lg-2 col-md-6">
                                        <label class="form-label fw-bold">To Date</label>
                                        <input type="date" class="form-control" name="to" id="dateTo" value="<?php echo h($dateTo ?: date('Y-m-d')); ?>" onchange="document.getElementById('auditFilterForm').submit();">
                                    </div>
                                    <div class="col-lg-2 col-md-12">
                                        <label class="form-label fw-bold">&nbsp;</label>
                                        <div class="d-grid">
                                            <a class="btn btn-outline-secondary" href="./">
                                                <i class="fas fa-undo me-1"></i>Clear
                                            </a>
                                        </div>
                                    </div>
                                </div>
                                <div class="row mt-3">
                                    <div class="col-md-8">
                                        <label class="form-label fw-bold">Search</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-search"></i></span>
                                            <input type="text" class="form-control" name="q" id="searchLogs" placeholder="Search actions, entities, or JSON data..." value="<?php echo h($q); ?>">
                                            <button type="submit" class="btn btn-primary">
                                                <i class="fas fa-search"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold">Quick Filters</label>
                                        <div class="d-flex gap-2">
                                            <a href="?action=approve" class="btn btn-sm btn-outline-success flex-fill">
                                                <i class="fas fa-check me-1"></i>Approvals
                                            </a>
                                            <a href="?action=login" class="btn btn-sm btn-outline-info flex-fill">
                                                <i class="fas fa-sign-in-alt me-1"></i>Logins
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Modern Audit Timeline -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                            <h6 class="mb-0">
                                <i class="fas fa-list me-2 text-primary"></i>
                                Audit Timeline
                            </h6>
                            <div class="text-muted small">
                                Showing <?php echo count($logs); ?> of <?php echo number_format($totalRows); ?> events
                            </div>
                        </div>
                        <div class="card-body p-0">
                                        <?php if (empty($logs)): ?>
                            <div class="empty-state text-center py-5">
                                <i class="fas fa-clipboard-list text-muted mb-3" style="font-size: 3rem;"></i>
                                <h5 class="text-muted">No audit records found</h5>
                                <p class="text-muted">No events match your current filters.</p>
                                <a href="./" class="btn btn-outline-primary">Clear Filters</a>
                                                </div>
                            <?php else: ?>
                            <div class="audit-timeline">
                                <?php foreach ($logs as $i => $r): 
                                    $actionColors = [
                                        'login' => 'info', 'logout' => 'secondary',
                                        'create' => 'primary', 'create_pending' => 'primary',
                                        'update' => 'warning', 'delete' => 'danger',
                                        'approve' => 'success', 'reject' => 'danger',
                                        'undo' => 'warning'
                                    ];
                                    $actionIcons = [
                                        'login' => 'fas fa-sign-in-alt', 'logout' => 'fas fa-sign-out-alt',
                                        'create' => 'fas fa-plus', 'create_pending' => 'fas fa-plus-circle',
                                        'update' => 'fas fa-edit', 'delete' => 'fas fa-trash',
                                        'approve' => 'fas fa-check', 'reject' => 'fas fa-times',
                                        'undo' => 'fas fa-undo'
                                    ];
                                    $color = $actionColors[$r['action']] ?? 'secondary';
                                    $icon = $actionIcons[$r['action']] ?? 'fas fa-circle';
                                ?>
                                <div class="timeline-item">
                                    <div class="timeline-marker bg-<?php echo $color; ?>">
                                        <i class="<?php echo $icon; ?> text-white"></i>
                                                    </div>
                                    <div class="timeline-content">
                                        <div class="timeline-header">
                                            <div class="d-flex justify-content-between align-items-start">
                                                    <div>
                                                    <h6 class="mb-1">
                                                        <span class="badge bg-<?php echo $color; ?> me-2">
                                                            <?php echo h(ucfirst(str_replace('_', ' ', $r['action']))); ?>
                                                        </span>
                                                        <?php echo h($r['entity_type']).' #'.(int)$r['entity_id']; ?>
                                                    </h6>
                                                    <div class="timeline-meta">
                                                        <span class="user-badge">
                                                            <i class="fas fa-user me-1"></i>
                                                            <?php echo h($r['user_name'] ?? 'System'); ?>
                                                            <?php if (!empty($r['user_role'])): ?>
                                                            <small class="text-muted">(<?php echo h($r['user_role']); ?>)</small>
                                                            <?php endif; ?>
                                                        </span>
                                                        <span class="text-muted mx-2">•</span>
                                                        <span class="timestamp">
                                                            <i class="fas fa-clock me-1"></i>
                                                            <?php echo date('d M Y, h:i:s A', strtotime($r['created_at'])); ?>
                                                        </span>
                                                        <?php if (!empty($r['ip_address'])): ?>
                                                        <span class="text-muted mx-2">•</span>
                                                        <span class="ip-address">
                                                            <i class="fas fa-globe me-1"></i>
                                                            <?php echo h(ip_from_binary($r['ip_address'])); ?>
                                                        </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="timeline-actions">
                                                    <button class="btn btn-sm btn-outline-primary" 
                                                            onclick='viewDetails(<?php echo json_encode([
                                                  "ts"=>$r["created_at"],
                                                              "user"=>$r["user_name"] ?? 'System',
                                                              "role"=>$r["user_role"] ?? '',
                                                  "action"=>$r["action"],
                                                              "entity"=>$r["entity_type"].' #'.$r["entity_id"],
                                                              "source"=>$r["source"] ?? '',
                                                  "ip"=>ip_from_binary($r["ip_address"]),
                                                  "before"=>$r["before_json"],
                                                  "after"=>$r["after_json"]
                                                            ]); ?>)' 
                                                            title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                </div>
                                            </div>
                                        </div>
                                        <?php if (!empty($r['source'])): ?>
                                        <div class="timeline-details mt-2">
                                            <small class="text-muted">
                                                <i class="fas fa-info-circle me-1"></i>
                                                Source: <?php echo h($r['source']); ?>
                                            </small>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($totalPages > 1): ?>
                            <!-- Modern Pagination -->
                            <div class="card-footer bg-transparent border-0">
                                <nav aria-label="Audit logs pagination">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="text-muted small">
                                            Page <?php echo $page; ?> of <?php echo $totalPages; ?>
                                        </div>
                                        <ul class="pagination mb-0">
                                    <?php $base=$_GET; unset($base['page']); $qs=http_build_query($base); ?>
                                    <li class="page-item <?php echo $page<=1?'disabled':''; ?>">
                                                <a class="page-link" href="?<?php echo $qs ? $qs.'&' : ''; ?>page=<?php echo max(1, $page-1); ?>" aria-label="Previous">
                                                    <span aria-hidden="true"><i class="fas fa-chevron-left"></i></span>
                                        </a>
                                    </li>
                                            <?php
                                            $renderedDotsLeft = false; $renderedDotsRight = false;
                                            for ($i = 1; $i <= $totalPages; $i++) {
                                                $isEdge = ($i === 1 || $i === $totalPages);
                                                $inWindow = ($i >= $page - 2 && $i <= $page + 2);
                                                if ($isEdge || $inWindow) {
                                                    $active = $i === $page ? ' active' : '';
                                                    echo '<li class="page-item'.$active.'"><a class="page-link" href="?'.($qs ? $qs.'&' : '').'page='.$i.'">'.$i.'</a></li>';
                                                } else {
                                                    if ($i < $page && !$renderedDotsLeft) { echo '<li class="page-item disabled"><span class="page-link">…</span></li>'; $renderedDotsLeft = true; }
                                                    if ($i > $page && !$renderedDotsRight) { echo '<li class="page-item disabled"><span class="page-link">…</span></li>'; $renderedDotsRight = true; }
                                                }
                                            }
                                            ?>
                                    <li class="page-item <?php echo $page>=$totalPages?'disabled':''; ?>">
                                                <a class="page-link" href="?<?php echo $qs ? $qs.'&' : ''; ?>page=<?php echo min($totalPages, $page+1); ?>" aria-label="Next">
                                                    <span aria-hidden="true"><i class="fas fa-chevron-right"></i></span>
                                        </a>
                                    </li>
                                </ul>
                                    </div>
                            </nav>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <!-- Modern Audit Details Modal -->
    <div class="modal fade" id="detailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-gradient-primary text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-search me-2"></i>
                        Audit Log Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="audit-detail-grid">
                        <div class="row g-3 mb-4">
                        <div class="col-md-6">
                                <div class="detail-card">
                                    <div class="detail-icon bg-info">
                                        <i class="fas fa-clock"></i>
                                    </div>
                                    <div class="detail-content">
                                        <label class="detail-label">Timestamp</label>
                                        <div class="detail-value" id="detailTimestamp">-</div>
                                    </div>
                                </div>
                        </div>
                        <div class="col-md-6">
                                <div class="detail-card">
                                    <div class="detail-icon bg-primary">
                                        <i class="fas fa-user"></i>
                                    </div>
                                    <div class="detail-content">
                                        <label class="detail-label">User</label>
                                        <div class="detail-value" id="detailUser">-</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <div class="detail-card">
                                    <div class="detail-icon bg-success">
                                        <i class="fas fa-cog"></i>
                                    </div>
                                    <div class="detail-content">
                                        <label class="detail-label">Action</label>
                                        <div class="detail-value" id="detailAction">-</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="detail-card">
                                    <div class="detail-icon bg-warning">
                                        <i class="fas fa-database"></i>
                                    </div>
                                    <div class="detail-content">
                                        <label class="detail-label">Entity</label>
                                        <div class="detail-value" id="detailEntity">-</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="detail-card">
                                    <div class="detail-icon bg-secondary">
                                        <i class="fas fa-globe"></i>
                                    </div>
                                    <div class="detail-content">
                                        <label class="detail-label">IP Address</label>
                                        <div class="detail-value" id="detailIP">-</div>
                                    </div>
                        </div>
                    </div>
                        </div>
                        <div class="row g-3 mb-4" id="sourceRow" style="display: none;">
                            <div class="col-12">
                                <div class="detail-card">
                                    <div class="detail-icon bg-info">
                                        <i class="fas fa-info-circle"></i>
                                    </div>
                                    <div class="detail-content">
                                        <label class="detail-label">Source</label>
                                        <div class="detail-value" id="detailSource">-</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="changes-section">
                            <h6 class="section-title">
                                <i class="fas fa-exchange-alt me-2"></i>
                                Changes Made
                            </h6>
                            <div class="changes-container">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <div class="change-box before">
                                            <div class="change-header">
                                                <i class="fas fa-arrow-left me-2"></i>Before
                                            </div>
                                            <pre id="detailBefore" class="change-content">-</pre>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="change-box after">
                                            <div class="change-header">
                                                <i class="fas fa-arrow-right me-2"></i>After
                                            </div>
                                            <pre id="detailAfter" class="change-content">-</pre>
                                        </div>
                                    </div>
                        </div>
                    </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Close
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/admin.js"></script>
    <script src="assets/audit.js"></script>
</body>
</html>
