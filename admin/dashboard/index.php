<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

// --- Resilient Database Loading ---
$settings = [];
$db_error_message = '';

try {
    $db = db();
    // Check if tables exist before querying
    $settings_table_exists = $db->query("SHOW TABLES LIKE 'settings'")->num_rows > 0;

    if ($settings_table_exists) {
        $settings = $db->query('SELECT target_amount, currency_code, display_token, refresh_seconds FROM settings WHERE id = 1')->fetch_assoc() ?: [];
    } else {
        $db_error_message .= '`settings` table not found. ';
    }

    // Use centralized FinancialCalculator for consistency
    require_once __DIR__ . '/../../shared/FinancialCalculator.php';
    
    $paidTotal = 0.0;
    $pledgedTotal = 0.0;
    $grandTotal = 0.0;
    
    try {
        $calculator = new FinancialCalculator();
        $totals = $calculator->getTotals();
        
        $paidTotal = $totals['total_paid'];
        $pledgedTotal = $totals['outstanding_pledged'];
        $grandTotal = $totals['grand_total'];
    } catch (Exception $calc_error) {
        $db_error_message .= 'Unable to calculate totals: ' . $calc_error->getMessage();
    }
} catch (Exception $e) {
    // This catches connection errors
    $db_error_message = 'Database connection failed: ' . $e->getMessage();
    $paidTotal = 0.0; $pledgedTotal = 0.0; $grandTotal = 0.0;
}
// --- End Resilient Loading ---

// Live totals for initial render (JS can refresh if needed)
$currency = htmlspecialchars($settings['currency_code'] ?? 'GBP', ENT_QUOTES, 'UTF-8');
$page_title = 'Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard - Fundraising Admin</title>
  <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="../assets/admin.css">
  <link rel="stylesheet" href="assets/dashboard.css">
  <?php include __DIR__ . '/../includes/pwa.php'; ?>
</head>
<body>
<div class="admin-wrapper">
  <?php include '../includes/sidebar.php'; ?>
  
  <div class="admin-content">
    <?php include '../includes/topbar.php'; ?>
    
    <main class="main-content">
    <?php if (!empty($db_error_message)): ?>
        <div class="alert alert-danger m-3">
            <strong><i class="fas fa-exclamation-triangle me-2"></i>Database Error:</strong>
            <?php echo htmlspecialchars($db_error_message); ?> Please go to <a href="../tools/import_helper.php">Tools -> Import</a> to restore the database.
        </div>
    <?php endif; ?>
      <script>
        window.DASHBOARD_TOKEN = <?php echo json_encode($settings['display_token'] ?? ''); ?>;
        window.DASHBOARD_REFRESH_SECS = <?php echo (int)($settings['refresh_seconds'] ?? 5); ?>;
        window.DASHBOARD_CURRENCY = <?php echo json_encode($currency); ?>;
      </script>
      <!-- Fundraising Progress Tracker -->
      <?php 
        $target = (float)($settings['target_amount'] ?? 0);
        $paid = $paidTotal;
        $pledged = $pledgedTotal;
        $grand = $grandTotal;
        $progressPct = $target > 0 ? min(100, round(($grand / $target) * 100, 2)) : 0;
      ?>
      <div class="progress-card animate-fade-in mb-4">
        <div class="progress-header">
          <h5 class="progress-title mb-0">
            <i class="fas fa-gauge-high text-primary me-2"></i>
            Fundraising Progress
          </h5>
        </div>
        <div class="progress-track">
          <?php 
            $paidPct = $target > 0 ? min(100, max(0, ($paid / $target) * 100)) : 0; 
            $pledgedPct = max(0, $progressPct - $paidPct);
          ?>
          <div class="progress-fill progress-paid" style="width: <?php echo $paidPct; ?>%"></div>
          <div class="progress-fill progress-pledged" style="left: <?php echo $paidPct; ?>%; width: <?php echo $pledgedPct; ?>%"></div>
          <div class="progress-shine"></div>
          <div class="progress-spark" style="left: <?php echo $progressPct; ?>%"></div>
          <div class="progress-milestones">
            <div class="milestone <?php echo $progressPct>=25?'reached':''; ?>" style="left:25%">
              <span class="tick"></span>
              <span class="label">25%</span>
            </div>
            <div class="milestone <?php echo $progressPct>=50?'reached':''; ?>" style="left:50%">
              <span class="tick"></span>
              <span class="label">50%</span>
            </div>
            <div class="milestone <?php echo $progressPct>=75?'reached':''; ?>" style="left:75%">
              <span class="tick"></span>
              <span class="label">75%</span>
            </div>
            <div class="milestone end <?php echo $progressPct>=100?'reached':''; ?>" style="left:100%">
              <span class="tick"></span>
              <span class="label">100%</span>
            </div>
          </div>
        </div>
        <div class="progress-caption">
          <div class="progress-percent"><strong><?php echo $progressPct; ?>%</strong> complete</div>
          <div class="progress-subtext">Raised <?php echo $currency . ' ' . number_format($grand, 2); ?> of <?php echo $currency . ' ' . number_format((float)$settings['target_amount'], 2); ?></div>
        </div>
      </div>
      <!-- Stats Grid -->
      <div class="row g-4 mb-4">
        <div class="col-12 col-sm-6 col-lg-4">
          <div class="stat-card animate-fade-in">
            <div class="stat-icon bg-success">
              <i class="fas fa-check-circle"></i>
            </div>
            <div class="stat-content">
              <h3 class="stat-value"><?php echo $currency . ' ' . number_format($paidTotal, 0); ?></h3>
              <p class="stat-label">Total Paid</p>
              <div class="stat-trend text-success">
                <i class="fas fa-arrow-up"></i> Confirmed
              </div>
            </div>
          </div>
        </div>
        
        <div class="col-12 col-sm-6 col-lg-4">
          <div class="stat-card animate-fade-in" style="animation-delay: 0.1s;">
            <div class="stat-icon bg-warning">
              <i class="fas fa-clock"></i>
            </div>
            <div class="stat-content">
              <h3 class="stat-value"><?php echo $currency . ' ' . number_format($pledgedTotal, 0); ?></h3>
              <p class="stat-label">Total Pledged</p>
              <div class="stat-trend text-warning">
                <i class="fas fa-hourglass-half"></i> Pending
              </div>
            </div>
          </div>
        </div>
        
        <div class="col-12 col-sm-6 col-lg-4">
          <div class="stat-card animate-fade-in" style="animation-delay: 0.2s;">
            <div class="stat-icon bg-primary">
              <i class="fas fa-chart-line"></i>
            </div>
            <div class="stat-content">
              <h3 class="stat-value text-primary"><?php echo $currency . ' ' . number_format($grandTotal, 0); ?></h3>
              <p class="stat-label">Grand Total</p>
              <div class="stat-trend text-primary">
                <i class="fas fa-plus-circle"></i> Combined
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Quick Actions -->
      <div class="row g-4 mb-4">
        <div class="col-12 col-lg-8">
          <div class="card animate-fade-in" style="animation-delay: 0.3s;">
            <div class="card-header">
              <h5 class="mb-0">Fast Tools</h5>
            </div>
            <div class="card-body">
              <div class="row g-3">
                <div class="col-6 col-md-4">
                  <a href="../approvals/" class="quick-action-btn">
                    <div class="quick-action-icon bg-success">
                      <i class="fas fa-check-circle"></i>
                    </div>
                    <span>Review Approvals</span>
                  </a>
                </div>
                <div class="col-6 col-md-4">
                  <a href="../approved/" class="quick-action-btn">
                    <div class="quick-action-icon bg-info">
                      <i class="fas fa-check-double"></i>
                    </div>
                    <span>Approved Items</span>
                  </a>
                </div>
                <div class="col-6 col-md-4">
                  <a href="../pledges/" class="quick-action-btn">
                    <div class="quick-action-icon bg-warning">
                      <i class="fas fa-hand-holding-usd"></i>
                    </div>
                    <span>View Pledges</span>
                  </a>
                </div>
                <div class="col-6 col-md-4">
                  <a href="../call-center/assign-donors.php" class="quick-action-btn">
                    <div class="quick-action-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                      <i class="fas fa-users-cog"></i>
                    </div>
                    <span>Assign Donors</span>
                  </a>
                </div>
                <div class="col-6 col-md-4">
                  <a href="../call-center/reports.php" class="quick-action-btn">
                    <div class="quick-action-icon" style="background: linear-gradient(135deg, #0a6286 0%, #0d6efd 100%);">
                      <i class="fas fa-chart-pie"></i>
                    </div>
                    <span>Call Center Reports</span>
                  </a>
                </div>
                <div class="col-6 col-md-4">
                  <a href="../donations/record-pledge-payment.php" class="quick-action-btn">
                    <div class="quick-action-icon" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                      <i class="fas fa-file-invoice-dollar"></i>
                    </div>
                    <span>Record Payment</span>
                  </a>
                </div>
                <div class="col-6 col-md-4">
                  <a href="../donations/review-pledge-payments.php" class="quick-action-btn">
                    <div class="quick-action-icon" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">
                      <i class="fas fa-check-double"></i>
                    </div>
                    <span>Review Payments</span>
                  </a>
                </div>
                <div class="col-6 col-md-4">
                  <a href="../donor-management/payments.php" class="quick-action-btn">
                    <div class="quick-action-icon" style="background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);">
                      <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <span>Payment Management</span>
                  </a>
                </div>
                <div class="col-6 col-md-4">
                  <a href="../settings/" class="quick-action-btn">
                    <div class="quick-action-icon bg-secondary">
                      <i class="fas fa-cog"></i>
                    </div>
                    <span>Settings</span>
                  </a>
                </div>
                <div class="col-6 col-md-4">
                  <a href="../../public/projector/" target="_blank" class="quick-action-btn">
                    <div class="quick-action-icon bg-primary">
                      <i class="fas fa-tv"></i>
                    </div>
                    <span>Projector View</span>
                  </a>
                </div>
                <div class="col-6 col-md-4">
                  <a href="../../registrar/" target="_blank" class="quick-action-btn">
                    <div class="quick-action-icon bg-dark">
                      <i class="fas fa-id-card"></i>
                    </div>
                    <span>Registrar Pages</span>
                  </a>
                </div>
                <div class="col-6 col-md-4">
                  <a href="../reports/" class="quick-action-btn">
                    <div class="quick-action-icon bg-danger">
                      <i class="fas fa-chart-bar"></i>
                    </div>
                    <span>View Reports</span>
                  </a>
                </div>
                <div class="col-6 col-md-4">
                  <a href="../tools/" class="quick-action-btn">
                    <div class="quick-action-icon bg-secondary">
                      <i class="fas fa-wrench"></i>
                    </div>
                    <span>Developer Tools</span>
                  </a>
                </div>
              </div>
            </div>
          </div>
        </div>
        
        <div class="col-12 col-lg-4">
          <div class="card animate-fade-in" style="animation-delay: 0.4s;">
            <div class="card-header">
              <h5 class="mb-0">Progress Overview</h5>
            </div>
            <div class="card-body">
              <?php 
              $target = (float)$settings['target_amount'];
              $progress = $target > 0 ? ($grandTotal / $target) * 100 : 0;
              ?>
              <div class="progress-circle mx-auto mb-3">
                <svg width="120" height="120">
                  <circle cx="60" cy="60" r="54" stroke="#e5e7eb" stroke-width="12" fill="none"></circle>
                  <circle cx="60" cy="60" r="54" stroke="var(--primary)" stroke-width="12" fill="none"
                          stroke-dasharray="339.292" 
                          stroke-dashoffset="<?php echo 339.292 * (1 - $progress / 100); ?>"
                          transform="rotate(-90 60 60)"></circle>
                </svg>
                <div class="progress-text">
                  <span class="progress-value"><?php echo round($progress); ?>%</span>
                </div>
              </div>
              <div class="text-center">
                <p class="mb-1 text-muted">Target Amount</p>
                <h5 class="mb-0"><?php echo $currency . ' ' . number_format($target, 2); ?></h5>
              </div>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Recent Activity -->
      <?php
        // Pull latest 6 audit log entries for a quick timeline
        $recent = db()->query(
          "SELECT al.created_at, al.action, al.entity_type, al.entity_id, al.after_json, u.name AS user_name
           FROM audit_logs al
           LEFT JOIN users u ON u.id = al.user_id
           ORDER BY al.created_at DESC
           LIMIT 6"
        );
        $recentRows = $recent ? $recent->fetch_all(MYSQLI_ASSOC) : [];
        function activityIconClass(string $action): array {
          switch ($action) {
            case 'approve': return ['bg-success','fa-check'];
            case 'reject': return ['bg-danger','fa-times'];
            case 'create': return ['bg-info','fa-plus'];
            case 'update': return ['bg-warning','fa-edit'];
            case 'login': return ['bg-primary','fa-right-to-bracket'];
            default: return ['bg-secondary','fa-circle-info'];
          }
        }
      ?>
      <div class="card animate-fade-in" style="animation-delay: 0.5s;">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="mb-0">Recent Activity</h5>
          <a href="../audit/" class="btn btn-sm btn-outline-primary">View All</a>
        </div>
        <div class="card-body">
          <div class="activity-timeline">
            <?php if (count($recentRows) === 0): ?>
              <p class="text-muted mb-0">No activity yet.</p>
            <?php else: ?>
              <?php foreach ($recentRows as $row): list($bg,$icon) = activityIconClass((string)$row['action']); ?>
                <div class="activity-item">
                  <div class="activity-icon <?php echo $bg; ?>">
                    <i class="fas <?php echo $icon; ?>"></i>
                  </div>
                  <div class="activity-content">
                    <p class="mb-1">
                      <strong><?php echo htmlspecialchars(ucfirst($row['action']), ENT_QUOTES,'UTF-8'); ?></strong>
                      on <?php echo htmlspecialchars($row['entity_type'].' #'.$row['entity_id'], ENT_QUOTES,'UTF-8'); ?>
                      <?php if (!empty($row['user_name'])): ?>by <?php echo htmlspecialchars($row['user_name'], ENT_QUOTES,'UTF-8'); ?><?php endif; ?>
                    </p>
                    <small class="text-muted"><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($row['created_at'])), ENT_QUOTES,'UTF-8'); ?></small>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </main>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script src="assets/dashboard.js"></script>
</body>
</html>
