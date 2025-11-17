<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/GridAllocationBatchTracker.php';
require_login();
require_admin();

$page_title = 'Manage Grid Cells';
$db = db();
$actionMsg = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    
    if ($action === 'unallocate_cell') {
        $cellId = trim((string)($_POST['cell_id'] ?? ''));
        if ($cellId) {
            $db->begin_transaction();
            try {
                // Get cell details
                $sel = $db->prepare("SELECT id, pledge_id, payment_id, allocation_batch_id, status FROM floor_grid_cells WHERE cell_id = ? FOR UPDATE");
                $sel->bind_param('s', $cellId);
                $sel->execute();
                $cell = $sel->get_result()->fetch_assoc();
                $sel->close();
                
                if (!$cell) {
                    throw new RuntimeException('Cell not found');
                }
                
                // If cell is allocated, we need to handle batch updates
                if ($cell['allocation_batch_id']) {
                    $batchId = (int)$cell['allocation_batch_id'];
                    $batchTracker = new GridAllocationBatchTracker($db);
                    $batch = $batchTracker->getBatchById($batchId);
                    
                    if ($batch && $batch['approval_status'] === 'approved') {
                        // Update batch allocation data
                        $allocatedCells = json_decode($batch['allocated_cell_ids'] ?? '[]', true) ?: [];
                        $allocatedCells = array_values(array_filter($allocatedCells, fn($id) => $id !== $cellId));
                        
                        $newCount = count($allocatedCells);
                        $newArea = $newCount * (float)($batch['allocated_area'] ?? 0) / max(1, (int)($batch['allocated_cell_count'] ?? 1));
                        
                        $updBatch = $db->prepare("
                            UPDATE grid_allocation_batches 
                            SET allocated_cell_ids = ?,
                                allocated_cell_count = ?,
                                allocated_area = ?,
                                updated_at = NOW()
                            WHERE id = ?
                        ");
                        $cellsJson = json_encode($allocatedCells);
                        $updBatch->bind_param('sidi', $cellsJson, $newCount, $newArea, $batchId);
                        $updBatch->execute();
                        $updBatch->close();
                    }
                }
                
                // Free the cell
                $upd = $db->prepare("
                    UPDATE floor_grid_cells 
                    SET status = 'available',
                        pledge_id = NULL,
                        payment_id = NULL,
                        allocation_batch_id = NULL,
                        donor_name = NULL,
                        amount = NULL,
                        assigned_date = NULL
                    WHERE cell_id = ?
                ");
                $upd->bind_param('s', $cellId);
                $upd->execute();
                $upd->close();
                
                // Audit log
                $uid = (int)(current_user()['id'] ?? 0);
                $before = json_encode([
                    'cell_id' => $cellId,
                    'status' => $cell['status'],
                    'pledge_id' => $cell['pledge_id'],
                    'batch_id' => $cell['allocation_batch_id']
                ], JSON_UNESCAPED_SLASHES);
                $after = json_encode([
                    'cell_id' => $cellId,
                    'status' => 'available',
                    'action' => 'unallocated'
                ], JSON_UNESCAPED_SLASHES);
                $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'grid_cell', ?, 'unallocate', ?, ?, 'admin')");
                $log->bind_param('iiss', $uid, $cell['id'], $before, $after);
                $log->execute();
                
                $db->commit();
                $actionMsg = "Cell {$cellId} unallocated successfully";
            } catch (Throwable $e) {
                $db->rollback();
                $actionMsg = 'Error: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'allocate_cell') {
        $cellId = trim((string)($_POST['cell_id'] ?? ''));
        $pledgeId = !empty($_POST['pledge_id']) ? (int)$_POST['pledge_id'] : null;
        $paymentId = !empty($_POST['payment_id']) ? (int)$_POST['payment_id'] : null;
        $donorName = trim((string)($_POST['donor_name'] ?? ''));
        $amount = (float)($_POST['amount'] ?? 0);
        $status = in_array($_POST['status'] ?? 'pledged', ['pledged', 'paid', 'blocked'], true) ? $_POST['status'] : 'pledged';
        
        if ($cellId && $donorName && $amount > 0) {
            $db->begin_transaction();
            try {
                // Check cell is available
                $sel = $db->prepare("SELECT id, status FROM floor_grid_cells WHERE cell_id = ? FOR UPDATE");
                $sel->bind_param('s', $cellId);
                $sel->execute();
                $cell = $sel->get_result()->fetch_assoc();
                $sel->close();
                
                if (!$cell) {
                    throw new RuntimeException('Cell not found');
                }
                
                if ($cell['status'] !== 'available') {
                    throw new RuntimeException('Cell is not available');
                }
                
                // Allocate the cell
                $upd = $db->prepare("
                    UPDATE floor_grid_cells 
                    SET status = ?,
                        pledge_id = ?,
                        payment_id = ?,
                        donor_name = ?,
                        amount = ?,
                        assigned_date = NOW()
                    WHERE cell_id = ?
                ");
                $upd->bind_param('siisds', $status, $pledgeId, $paymentId, $donorName, $amount, $cellId);
                $upd->execute();
                $upd->close();
                
                // Audit log
                $uid = (int)(current_user()['id'] ?? 0);
                $before = json_encode(['cell_id' => $cellId, 'status' => 'available'], JSON_UNESCAPED_SLASHES);
                $after = json_encode([
                    'cell_id' => $cellId,
                    'status' => $status,
                    'pledge_id' => $pledgeId,
                    'payment_id' => $paymentId,
                    'donor_name' => $donorName,
                    'amount' => $amount
                ], JSON_UNESCAPED_SLASHES);
                $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'grid_cell', ?, 'allocate', ?, ?, 'admin')");
                $log->bind_param('iiss', $uid, $cell['id'], $before, $after);
                $log->execute();
                
                $db->commit();
                $actionMsg = "Cell {$cellId} allocated to {$donorName} successfully";
            } catch (Throwable $e) {
                $db->rollback();
                $actionMsg = 'Error: ' . $e->getMessage();
            }
        }
    }
    
    if ($actionMsg) {
         // Preserve filter parameters in redirect
         $redirectParams = ['msg' => $actionMsg];
         foreach (['rectangle', 'status', 'page'] as $param) {
             if (isset($_GET[$param])) {
                 $redirectParams[$param] = $_GET[$param];
             }
         }
        header('Location: manage.php?' . http_build_query($redirectParams));
        exit;
    }
}

// Get filter parameters
$filter_rectangle = $_GET['rectangle'] ?? 'A';
$filter_status = $_GET['status'] ?? 'all';

// Validate rectangle
if (!in_array($filter_rectangle, ['A', 'B', 'C', 'D', 'E', 'F', 'G'], true)) {
    $filter_rectangle = 'A';
}

// CRITICAL: Only show 0.5x0.5 cells (the atomic unit)
// All other cell types (1x1, 1x0.5) are composed of these
$where_conditions = ['c.rectangle_id = ?', "c.cell_type = '0.5x0.5'"];
$params = [$filter_rectangle];
$types = 's';

if ($filter_status !== 'all' && in_array($filter_status, ['available', 'pledged', 'paid', 'blocked'], true)) {
    $where_conditions[] = 'c.status = ?';
    $params[] = $filter_status;
    $types .= 's';
}

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 100; // Show 100 cells per page
$offset = ($page - 1) * $per_page;

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total FROM floor_grid_cells c $where_clause";
$count_stmt = $db->prepare($count_sql);
if ($count_stmt) {
    if (!empty($params)) {
        $count_stmt->bind_param($types, ...$params);
    }
    $count_stmt->execute();
    $total_result = $count_stmt->get_result()->fetch_assoc();
    $total_cells = (int)($total_result['total'] ?? 0);
    $total_pages = (int)ceil($total_cells / $per_page);
    $count_stmt->close();
} else {
    $total_cells = 0;
    $total_pages = 1;
}

// Get cells with pagination
$sql = "
    SELECT 
        c.id,
        c.cell_id,
        c.rectangle_id,
        c.cell_type,
        c.area_size,
        c.status,
        c.pledge_id,
        c.payment_id,
        c.allocation_batch_id,
        c.donor_name,
        c.amount,
        c.assigned_date,
        p.amount as pledge_amount,
        p.status as pledge_status,
        pay.amount as payment_amount,
        pay.status as payment_status,
        b.batch_type,
        b.approval_status as batch_status,
        CAST(SUBSTRING_INDEX(c.cell_id, '-', -1) AS UNSIGNED) as cell_number
    FROM floor_grid_cells c
    LEFT JOIN pledges p ON c.pledge_id = p.id
    LEFT JOIN payments pay ON c.payment_id = pay.id
    LEFT JOIN grid_allocation_batches b ON c.allocation_batch_id = b.id
    $where_clause
    ORDER BY c.cell_id
    LIMIT ? OFFSET ?
";

$stmt = $db->prepare($sql);
if ($stmt) {
    $limit = $per_page;
    $offset_val = $offset;
    if (!empty($params)) {
        // Add limit and offset to types and params
        $types_with_limit = $types . 'ii';
        $params_with_limit = array_merge($params, [$limit, $offset_val]);
        $stmt->bind_param($types_with_limit, ...$params_with_limit);
    } else {
        $stmt->bind_param('ii', $limit, $offset_val);
    }
    $stmt->execute();
    $cells = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $cells = [];
    $page_error = "Database error: " . htmlspecialchars($db->error);
}

// Get statistics for selected filters
$stats_sql = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available,
        SUM(CASE WHEN status = 'pledged' THEN 1 ELSE 0 END) as pledged,
        SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid,
        SUM(CASE WHEN status = 'blocked' THEN 1 ELSE 0 END) as blocked
    FROM floor_grid_cells c
    $where_clause
";
$stats_stmt = $db->prepare($stats_sql);
if ($stats_stmt) {
    if (!empty($params)) {
        $stats_stmt->bind_param($types, ...$params);
    }
    $stats_stmt->execute();
    $stats = $stats_stmt->get_result()->fetch_assoc();
    $stats_stmt->close();
} else {
    $stats = ['total' => 0, 'available' => 0, 'pledged' => 0, 'paid' => 0, 'blocked' => 0];
}

// Get all pledges and payments for allocation dropdown
$pledges = $db->query("SELECT id, donor_name, amount, status FROM pledges WHERE status = 'approved' ORDER BY donor_name LIMIT 100")->fetch_all(MYSQLI_ASSOC);
$payments = $db->query("SELECT id, donor_name, amount, status FROM payments WHERE status = 'approved' ORDER BY donor_name LIMIT 100")->fetch_all(MYSQLI_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo htmlspecialchars($page_title); ?> - Fundraising Admin</title>
  <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="../assets/admin.css?v=<?php echo @filemtime(__DIR__ . '/../assets/admin.css'); ?>">
  <link rel="stylesheet" href="assets/grid-manage.css?v=<?php echo @filemtime(__DIR__ . '/assets/grid-manage.css'); ?>">
</head>
<body>
<div class="admin-wrapper">
  <?php include '../includes/sidebar.php'; ?>
  <div class="admin-content">
    <?php include '../includes/topbar.php'; ?>
    <main class="main-content">
      <div class="row mb-4">
        <div class="col-12">
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <h1 class="h3 mb-0">
                <i class="fas fa-cog me-2"></i>Manage Grid Cells
              </h1>
              <p class="text-muted mb-0">Manage individual floor grid cells - allocate, unallocate, and reassign</p>
            </div>
            <a href="index.php" class="btn btn-outline-secondary">
              <i class="fas fa-arrow-left me-2"></i>Back to Viewer
            </a>
          </div>
        </div>
      </div>

      <?php if (!empty($_GET['msg'])): ?>
      <div class="alert alert-info alert-dismissible fade show" role="alert">
        <i class="fas fa-info-circle me-2"></i><?php echo htmlspecialchars($_GET['msg']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
      <?php endif; ?>

      <?php if (isset($page_error)): ?>
      <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($page_error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
      <?php endif; ?>

      <!-- Filters -->
      <div class="card mb-4">
        <div class="card-header bg-light">
          <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filters</h5>
        </div>
        <div class="card-body">
          <form method="GET" action="manage.php" class="row g-3">
            <div class="col-md-3">
              <label class="form-label">Rectangle</label>
              <select name="rectangle" class="form-select">
                <?php foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $rect): ?>
                <option value="<?php echo $rect; ?>" <?php echo $filter_rectangle === $rect ? 'selected' : ''; ?>>
                  Rectangle <?php echo $rect; ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
             <div class="col-md-4">
              <label class="form-label">Status</label>
              <select name="status" class="form-select">
                <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Status</option>
                <option value="available" <?php echo $filter_status === 'available' ? 'selected' : ''; ?>>Available</option>
                <option value="pledged" <?php echo $filter_status === 'pledged' ? 'selected' : ''; ?>>Pledged</option>
                <option value="paid" <?php echo $filter_status === 'paid' ? 'selected' : ''; ?>>Paid</option>
                <option value="blocked" <?php echo $filter_status === 'blocked' ? 'selected' : ''; ?>>Blocked</option>
              </select>
            </div>
             <div class="col-md-4 d-flex align-items-end">
               <button type="submit" class="btn btn-primary w-100">
                 <i class="fas fa-search me-2"></i>Apply Filters
               </button>
             </div>
           </form>
           <div class="alert alert-info mt-3 mb-0">
             <i class="fas fa-info-circle me-2"></i>
             <strong>Note:</strong> Showing only 0.5×0.5 cells (atomic units). 
             4 consecutive cells form one complete 0.50 m² box (£400). 
             Multiple donors can share one box.
           </div>
         </div>
       </div>

      <!-- Statistics -->
      <div class="row mb-4">
        <div class="col-md-3">
          <div class="card text-center">
            <div class="card-body">
              <h3 class="mb-0"><?php echo (int)$stats['total']; ?></h3>
              <small class="text-muted">Total Cells</small>
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="card text-center border-success">
            <div class="card-body">
              <h3 class="mb-0 text-success"><?php echo (int)$stats['available']; ?></h3>
              <small class="text-muted">Available</small>
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="card text-center border-warning">
            <div class="card-body">
              <h3 class="mb-0 text-warning"><?php echo (int)$stats['pledged']; ?></h3>
              <small class="text-muted">Pledged</small>
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="card text-center border-primary">
            <div class="card-body">
              <h3 class="mb-0 text-primary"><?php echo (int)$stats['paid']; ?></h3>
              <small class="text-muted">Paid</small>
            </div>
          </div>
        </div>
      </div>

      <!-- Grid Display -->
      <div class="card">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
          <h5 class="mb-0">
            <i class="fas fa-th me-2"></i>Cells
            <span class="badge bg-primary ms-2"><?php echo count($cells); ?></span>
          </h5>
        </div>
        <div class="card-body">
           <?php if (empty($cells)): ?>
           <div class="text-center py-5 text-muted">
             <i class="fas fa-th fa-3x mb-3"></i>
             <p>No cells found matching the selected filters.</p>
           </div>
           <?php else: ?>
           <?php
           // Calculate box number for each cell (every 4 cells = 1 box)
           // Box number helps identify which "complete box" a cell belongs to
           // Use cell_number from database (extracted from cell_id) for accurate box calculation
           foreach ($cells as &$cell) {
               $cellNumber = (int)($cell['cell_number'] ?? 0);
               if ($cellNumber > 0) {
                   // Box number: every 4 consecutive cells = 1 box
                   // Position: 1-4 within that box
                   $cell['box_number'] = (int)floor(($cellNumber - 1) / 4) + 1;
                   $cell['position_in_box'] = (($cellNumber - 1) % 4) + 1; // 1, 2, 3, or 4
               } else {
                   // Fallback if cell_number couldn't be extracted
                   $cell['box_number'] = 0;
                   $cell['position_in_box'] = 0;
               }
           }
           unset($cell);
           ?>
           <div class="grid-container">
             <?php foreach ($cells as $cell): ?>
             <?php
                 $statusClass = 'secondary';
                 if ($cell['status'] === 'available') $statusClass = 'success';
                 elseif ($cell['status'] === 'pledged') $statusClass = 'warning';
                 elseif ($cell['status'] === 'paid') $statusClass = 'primary';
                 elseif ($cell['status'] === 'blocked') $statusClass = 'danger';
                 
                 // Tooltip showing box info
                 $boxInfo = $cell['box_number'] > 0 
                     ? "Box #{$cell['box_number']}, Position {$cell['position_in_box']}/4"
                     : htmlspecialchars($cell['cell_id']);
             ?>
             <div class="grid-cell-cell status-<?php echo htmlspecialchars($cell['status']); ?>" 
                  data-cell-id="<?php echo htmlspecialchars($cell['cell_id']); ?>"
                  data-status="<?php echo htmlspecialchars($cell['status']); ?>"
                  data-pledge-id="<?php echo $cell['pledge_id'] ?? ''; ?>"
                  data-payment-id="<?php echo $cell['payment_id'] ?? ''; ?>"
                  data-batch-id="<?php echo $cell['allocation_batch_id'] ?? ''; ?>"
                  data-donor-name="<?php echo htmlspecialchars($cell['donor_name'] ?? ''); ?>"
                  data-amount="<?php echo $cell['amount'] ?? 0; ?>"
                  data-cell-type="<?php echo htmlspecialchars($cell['cell_type']); ?>"
                  data-area-size="<?php echo $cell['area_size']; ?>"
                  data-box-number="<?php echo $cell['box_number']; ?>"
                  data-position-in-box="<?php echo $cell['position_in_box']; ?>"
                  title="<?php echo htmlspecialchars($boxInfo); ?>"
                  role="button" tabindex="0">
               <div class="cell-id"><?php echo htmlspecialchars($cell['cell_id']); ?></div>
               <?php if ($cell['box_number'] > 0): ?>
               <div class="cell-box-info">
                 <small class="text-muted">Box #<?php echo $cell['box_number']; ?> (<?php echo $cell['position_in_box']; ?>/4)</small>
               </div>
               <?php endif; ?>
               <div class="cell-status">
                 <span class="badge bg-<?php echo $statusClass; ?>"><?php echo ucfirst($cell['status']); ?></span>
               </div>
               <?php if (!empty($cell['donor_name'])): ?>
               <div class="cell-donor"><?php echo htmlspecialchars($cell['donor_name']); ?></div>
               <?php endif; ?>
               <?php if (!empty($cell['amount']) && (float)$cell['amount'] > 0): ?>
               <div class="cell-amount">£<?php echo number_format((float)$cell['amount'], 0); ?></div>
               <?php endif; ?>
             </div>
             <?php endforeach; ?>
           </div>
           
           <!-- Pagination -->
           <?php if ($total_pages > 1): ?>
           <nav aria-label="Grid cells pagination" class="mt-4">
             <div class="d-flex justify-content-between align-items-center mb-3">
               <span class="text-muted">
                 Showing <?php echo min($offset + 1, $total_cells); ?> to 
                 <?php echo min($offset + count($cells), $total_cells); ?> of <?php echo $total_cells; ?> cells
               </span>
             </div>
             <ul class="pagination pagination-sm justify-content-center">
               <?php if ($page > 1): ?>
               <li class="page-item">
                 <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" aria-label="First">
                   <i class="fas fa-angle-double-left"></i>
                 </a>
               </li>
               <li class="page-item">
                 <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" aria-label="Previous">
                   <i class="fas fa-angle-left"></i>
                 </a>
               </li>
               <?php endif; ?>
               
               <?php
               $start = max(1, $page - 2);
               $end = min($total_pages, $page + 2);
               
               for ($i = $start; $i <= $end; $i++):
               ?>
               <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                 <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
               </li>
               <?php endfor; ?>
               
               <?php if ($page < $total_pages): ?>
               <li class="page-item">
                 <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" aria-label="Next">
                   <i class="fas fa-angle-right"></i>
                 </a>
               </li>
               <li class="page-item">
                 <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" aria-label="Last">
                   <i class="fas fa-angle-double-right"></i>
                 </a>
               </li>
               <?php endif; ?>
             </ul>
           </nav>
           <?php endif; ?>
           <?php endif; ?>
        </div>
      </div>
    </main>
  </div>
</div>

<!-- Cell Detail Modal -->
<div class="modal fade" id="cellDetailModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">
          <i class="fas fa-info-circle me-2"></i>Cell Details: <span id="modalCellId">-</span>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="cellDetailsContent">
          <div class="text-center py-3">
            <i class="fas fa-spinner fa-spin"></i> Loading...
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Unallocate Confirmation Modal -->
<div class="modal fade" id="unallocateModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-warning">
        <h5 class="modal-title">
          <i class="fas fa-exclamation-triangle me-2"></i>Unallocate Cell
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="manage.php">
        <div class="modal-body">
          <?php echo csrf_input(); ?>
          <input type="hidden" name="action" value="unallocate_cell">
          <input type="hidden" name="cell_id" id="unallocateCellId">
          <?php
          // Preserve filter parameters
          foreach (['rectangle', 'status', 'page'] as $param) {
              if (isset($_GET[$param])) {
                  echo '<input type="hidden" name="' . htmlspecialchars($param) . '" value="' . htmlspecialchars($_GET[$param]) . '">';
              }
          }
          ?>
          <p>Are you sure you want to unallocate cell <strong id="unallocateCellIdDisplay">-</strong>?</p>
          <div class="alert alert-warning">
            <i class="fas fa-info-circle me-2"></i>
            This will free the cell and make it available for allocation. 
            If this cell is part of a batch, the batch allocation data will be updated.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning">
            <i class="fas fa-unlink me-2"></i>Unallocate Cell
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Allocate Modal -->
<div class="modal fade" id="allocateModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-success">
        <h5 class="modal-title">
          <i class="fas fa-link me-2"></i>Allocate Cell
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="manage.php">
        <div class="modal-body">
          <?php echo csrf_input(); ?>
          <input type="hidden" name="action" value="allocate_cell">
          <input type="hidden" name="cell_id" id="allocateCellId">
          <?php
          // Preserve filter parameters
          foreach (['rectangle', 'status', 'page'] as $param) {
              if (isset($_GET[$param])) {
                  echo '<input type="hidden" name="' . htmlspecialchars($param) . '" value="' . htmlspecialchars($_GET[$param]) . '">';
              }
          }
          ?>
          
          <div class="mb-3">
            <label class="form-label">Donor Name <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="donor_name" id="allocateDonorName" required>
          </div>
          
          <div class="mb-3">
            <label class="form-label">Amount (£) <span class="text-danger">*</span></label>
            <input type="number" class="form-control" name="amount" id="allocateAmount" step="0.01" min="0" required>
          </div>
          
          <div class="mb-3">
            <label class="form-label">Status</label>
            <select class="form-select" name="status" id="allocateStatus">
              <option value="pledged">Pledged</option>
              <option value="paid">Paid</option>
              <option value="blocked">Blocked</option>
            </select>
          </div>
          
          <div class="mb-3">
            <label class="form-label">Link to Pledge (Optional)</label>
            <select class="form-select" name="pledge_id" id="allocatePledgeId">
              <option value="">— None —</option>
              <?php foreach ($pledges as $pledge): ?>
              <option value="<?php echo (int)$pledge['id']; ?>">
                <?php echo htmlspecialchars($pledge['donor_name']); ?> - £<?php echo number_format((float)$pledge['amount'], 2); ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          
          <div class="mb-3">
            <label class="form-label">Link to Payment (Optional)</label>
            <select class="form-select" name="payment_id" id="allocatePaymentId">
              <option value="">— None —</option>
              <?php foreach ($payments as $payment): ?>
              <option value="<?php echo (int)$payment['id']; ?>">
                <?php echo htmlspecialchars($payment['donor_name']); ?> - £<?php echo number_format((float)$payment['amount'], 2); ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success">
            <i class="fas fa-link me-2"></i>Allocate Cell
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js?v=<?php echo @filemtime(__DIR__ . '/../assets/admin.js'); ?>"></script>
<script src="assets/grid-manage.js?v=<?php echo @filemtime(__DIR__ . '/assets/grid-manage.js'); ?>"></script>
</body>
</html>

