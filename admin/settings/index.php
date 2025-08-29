<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_admin();

// Use the resilient loader to safely get DB status and settings
require_once __DIR__ . '/../includes/resilient_db_loader.php';

$page_title = 'Settings';
$msg = '';

 if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db_connection_ok) { // Only process POST if DB is connected
     verify_csrf();
    $action = $_POST['action'] ?? '';

    // AJAX actions
    if (in_array($action, ['add_package', 'update_package', 'delete_package'])) {
         header('Content-Type: application/json');
        $response = ['status' => 'error', 'message' => 'An unknown error occurred.'];

        if ($action === 'update_package') {
            $packageId = (int)($_POST['package_id'] ?? 0);
            $label = trim((string)($_POST['label'] ?? ''));
            $price = (float)($_POST['price'] ?? 0);
            $sqm = (float)($_POST['sqm_meters'] ?? 0);

            if ($packageId > 0 && !empty($label) && $price > 0) {
                $stmt = $db->prepare('UPDATE donation_packages SET label=?, price=?, sqm_meters=? WHERE id=?');
                $stmt->bind_param('sddi', $label, $price, $sqm, $packageId);
                if ($stmt->execute()) {
                    $response = ['status' => 'success', 'message' => 'Package updated successfully.'];
                } else {
                    $response['message'] = 'Database error during update.';
                }
            } else {
                $response['message'] = 'Invalid data provided for package update.';
            }
        } elseif ($action === 'add_package') {
            $label = trim((string)($_POST['label'] ?? ''));
            $price = (float)($_POST['price'] ?? 0);
            $sqm = (float)($_POST['sqm_meters'] ?? 0);

            if (!empty($label) && $price > 0) {
                $stmt = $db->prepare('INSERT INTO donation_packages (label, price, sqm_meters) VALUES (?, ?, ?)');
                $stmt->bind_param('sdd', $label, $price, $sqm);
                if ($stmt->execute()) {
                     $response = ['status' => 'success', 'message' => 'Package added successfully.', 'new_id' => $db->insert_id];
                } else {
                    $response['message'] = 'Database error during insert.';
                }
            } else {
                $response['message'] = 'Invalid data provided for new package.';
            }
        } elseif ($action === 'delete_package') {
            $packageId = (int)($_POST['package_id'] ?? 0);
            if ($packageId > 0) {
                // Check if package is in use
                $stmt_check = $db->prepare('SELECT (SELECT COUNT(*) FROM pledges WHERE package_id = ?) + (SELECT COUNT(*) FROM payments WHERE package_id = ?) as total');
                $stmt_check->bind_param('ii', $packageId, $packageId);
                $stmt_check->execute();
                $in_use_count = (int) $stmt_check->get_result()->fetch_assoc()['total'];

                if ($in_use_count > 0) {
                    $response['message'] = 'Cannot delete: This package is already linked to donations.';
                } else {
                    $stmt = $db->prepare('DELETE FROM donation_packages WHERE id=?');
                    $stmt->bind_param('i', $packageId);
                    if ($stmt->execute()) {
                        $response = ['status' => 'success', 'message' => 'Package deleted successfully.'];
                    } else {
                        $response['message'] = 'Database error during deletion.';
                    }
                }
            } else {
                $response['message'] = 'Invalid Package ID for deletion.';
            }
        }

        echo json_encode($response);
         exit;
    }

    // Full page submissions
    if ($action === 'save') {
         $target = (float)($_POST['target_amount'] ?? 0);
         $currency = substr(trim($_POST['currency_code'] ?? 'GBP'), 0, 3);
         $projectorMode = trim($_POST['projector_display_mode'] ?? 'amount');
         
         // Validate projector display mode
         $validModes = ['amount', 'sqm', 'both'];
         if (!in_array($projectorMode, $validModes)) {
             $projectorMode = 'amount';
         }
        
        $stmt = $db->prepare('UPDATE settings SET target_amount=?, currency_code=?, projector_display_mode=? WHERE id=1');
        $stmt->bind_param('dss', $target, $currency, $projectorMode);
         $stmt->execute();
        $msg = 'Settings updated successfully';
        // Re-fetch settings after update
        $settings = $db->query('SELECT * FROM settings WHERE id=1')->fetch_assoc() ?: $settings;
     }
 }

// Fetch donation packages safely
$donationPackages = [];
if ($db_connection_ok) {
    try {
        $packages_table_exists = $db->query("SHOW TABLES LIKE 'donation_packages'")->num_rows > 0;
        if ($packages_table_exists) {
            $donationPackages = $db->query('SELECT * FROM donation_packages ORDER BY price ASC')->fetch_all(MYSQLI_ASSOC);
        } else {
             if (empty($db_error_message)) $db_error_message = '`donation_packages` table not found.';
        }
    } catch (Exception $e) {
        if (empty($db_error_message)) $db_error_message = 'Could not load donation packages.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Settings - Fundraising Admin</title>
  <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="../assets/admin.css">
  <link rel="stylesheet" href="assets/settings.css">
</head>
<body>
<div class="admin-wrapper">
  <?php include '../includes/sidebar.php'; ?>
  
  <div class="admin-content">
    <?php include '../includes/topbar.php'; ?>
    
    <main class="main-content">
      <div class="container-fluid">
        <?php include '../includes/db_error_banner.php'; ?>
        
        <!-- Page Header (actions only) -->
        <div class="d-flex justify-content-end mb-4">
          <div class="d-flex gap-2">
            <button class="btn btn-outline-primary" id="refreshDataBtn">
              <i class="fas fa-sync-alt me-2"></i>Refresh
            </button>
          </div>
        </div>

        <div id="notification-container" class="position-fixed top-0 end-0 p-3" style="z-index: 1100"></div>

      <?php if ($msg): ?>
        <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
          <i class="fas fa-check-circle me-2"></i>
          <?php echo htmlspecialchars($msg); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>

      <!-- Settings Sections -->
      <div class="row g-4">
          <!-- Basic Fundraising Settings -->
          <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
              <div class="card-header bg-transparent border-0">
              <h5 class="mb-0">
                  <i class="fas fa-target me-2 text-primary"></i>
                  Fundraising Settings
              </h5>
            </div>
            <div class="card-body">
                <form method="post" id="mainSettingsForm">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="save">
                
                  <div class="mb-3">
                    <label class="form-label fw-bold">Target Amount</label>
                    <div class="input-group input-group-lg">
                        <span class="input-group-text"><?php echo htmlspecialchars($settings['currency_code']); ?></span>
                      <input type="number" step="0.01" name="target_amount" class="form-control" 
                               value="<?php echo htmlspecialchars($settings['target_amount']); ?>" required>
                  </div>
                </div>

                  <div class="mb-3">
                    <label class="form-label fw-bold">Currency Code</label>
                    <input type="text" maxlength="3" name="currency_code" class="form-control" 
                           value="<?php echo htmlspecialchars($settings['currency_code']); ?>" required>
                    <small class="text-muted">3-letter code (GBP, USD, EUR)</small>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">Projector Display Mode</label>
                    <select name="projector_display_mode" class="form-select">
                        <option value="amount" <?php echo ($settings['projector_display_mode'] ?? 'amount') === 'amount' ? 'selected' : ''; ?>>
                            Show Amount Only (Alemu pledged GBP 400)
                        </option>
                        <option value="sqm" <?php echo ($settings['projector_display_mode'] ?? 'amount') === 'sqm' ? 'selected' : ''; ?>>
                            Show Square Meters Only (Alemu pledged 1 Square Meter)
                        </option>
                        <option value="both" <?php echo ($settings['projector_display_mode'] ?? 'amount') === 'both' ? 'selected' : ''; ?>>
                            Show Both (Alemu pledged 1 Square Meter (£400))
                        </option>
                    </select>
                    <small class="text-muted">Choose how donations appear on the live projector display</small>
                </div>

                <div class="d-flex justify-content-end mt-4">
                    <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save me-2"></i>Save Settings
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>

          <!-- Donation Packages -->
          <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
              <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
              <h5 class="mb-0">
                  <i class="fas fa-tags me-2 text-success"></i>
                  Donation Packages
              </h5>
                <button type="button" class="btn btn-success btn-sm" id="addPackageBtn">
                  <i class="fas fa-plus me-1"></i>Add Package
                </button>
            </div>
            <div class="card-body">
                <div class="packages-list" id="packagesContainer">
                  <?php if (empty($donationPackages)): ?>
                    <div class="text-center text-muted py-4">
                      <i class="fas fa-plus-circle fa-3x mb-3"></i>
                      <p>No packages yet. Click "Add Package" to create your first donation package.</p>
                    </div>
                  <?php else: ?>
                    <?php foreach ($donationPackages as $pkg): ?>
                    <div class="package-item d-flex justify-content-between align-items-center p-3 mb-2 border rounded" data-package-id="<?php echo $pkg['id']; ?>">
                      <div class="package-info">
                        <h6 class="mb-1"><?php echo htmlspecialchars($pkg['label']); ?></h6>
                        <div class="package-details">
                          <span class="badge bg-success me-2"><?php echo htmlspecialchars($settings['currency_code']); ?> <?php echo number_format((float)$pkg['price'], 2); ?></span>
                          <?php if ((float)$pkg['sqm_meters'] > 0): ?>
                          <span class="badge bg-info"><?php echo number_format((float)$pkg['sqm_meters'], 2); ?> m²</span>
                          <?php endif; ?>
                        </div>
                      </div>
                      <div class="package-actions d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-primary btn-edit-package" 
                                data-package-id="<?php echo $pkg['id']; ?>" 
                                data-label="<?php echo htmlspecialchars($pkg['label']); ?>" 
                                data-price="<?php echo $pkg['price']; ?>" 
                                data-sqm="<?php echo $pkg['sqm_meters']; ?>">
                          <i class="fas fa-edit"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger btn-delete-package" data-package-id="<?php echo $pkg['id']; ?>">
                          <i class="fas fa-trash"></i>
                  </button>
                      </div>
                    </div>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>
              </div>
            </div>
              </div>
              
          <!-- User & Access Settings -->
          <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
              <div class="card-header bg-transparent border-0">
                <h5 class="mb-0">
                  <i class="fas fa-users me-2 text-info"></i>
                  User & Access Settings
                </h5>
              </div>
              <div class="card-body">
              <form method="post">
                <?php echo csrf_input(); ?>
                  <input type="hidden" name="action" value="save">
                  
                  <div class="mb-3">
                    <label class="form-label fw-bold">Session Timeout</label>
                    <select class="form-select" name="session_timeout">
                      <option value="1800" <?php echo ((int)($settings['session_timeout'] ?? 1800))===1800?'selected':''; ?>>
                        30 minutes
                      </option>
                      <option value="3600" <?php echo ((int)($settings['session_timeout'] ?? 1800))===3600?'selected':''; ?>>
                        1 hour
                      </option>
                      <option value="7200" <?php echo ((int)($settings['session_timeout'] ?? 1800))===7200?'selected':''; ?>>
                        2 hours
                      </option>
                      <option value="14400" <?php echo ((int)($settings['session_timeout'] ?? 1800))===14400?'selected':''; ?>>
                        4 hours
                      </option>
                    </select>
                    <small class="text-muted">How long users stay logged in when inactive</small>
                  </div>
                  
                  <div class="mb-3">
                    <label class="form-label fw-bold">Registration Mode</label>
                    <select class="form-select" name="registration_mode">
                      <option value="admin_only" <?php echo ($settings['registration_mode'] ?? 'admin_only')==='admin_only'?'selected':''; ?>>
                        Admin Creates Accounts
                      </option>
                      <option value="approval" <?php echo ($settings['registration_mode'] ?? 'admin_only')==='approval'?'selected':''; ?>>
                        Registration with Approval
                      </option>
                      <option value="open" <?php echo ($settings['registration_mode'] ?? 'admin_only')==='open'?'selected':''; ?>>
                        Open Registration
                      </option>
                    </select>
                  </div>
                  
                  <div class="mb-3">
                    <label class="form-label fw-bold">Default User Role</label>
                    <select class="form-select" name="default_role">
                      <option value="registrar" <?php echo ($settings['default_role'] ?? 'registrar')==='registrar'?'selected':''; ?>>
                        Registrar
                      </option>
                      <option value="viewer" <?php echo ($settings['default_role'] ?? 'registrar')==='viewer'?'selected':''; ?>>
                        Viewer Only
                      </option>
                    </select>
                  </div>
                  
                  <button type="submit" class="btn btn-info w-100">
                    <i class="fas fa-save me-2"></i>Save User Settings
                </button>
              </form>
              </div>
            </div>
          </div>
          
          <!-- System Tools & Maintenance -->
          <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
              <div class="card-header bg-transparent border-0">
              <h5 class="mb-0">
                  <i class="fas fa-tools me-2 text-warning"></i>
                  System Tools & Maintenance
              </h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                <!-- Recalculate Totals -->
                  <div class="col-md-6">
                    <div class="tool-card">
                      <div class="tool-icon bg-primary">
                    <i class="fas fa-calculator"></i>
                  </div>
                  <div class="tool-content">
                    <h6>Recalculate Totals</h6>
                        <p class="text-muted mb-2">Refresh all fundraising totals</p>
                    <form method="post" class="d-inline">
                      <?php echo csrf_input(); ?>
                      <input type="hidden" name="action" value="recalc_totals">
                          <button class="btn btn-sm btn-primary w-100" type="submit">
                        <i class="fas fa-sync me-1"></i>Recalculate
                      </button>
                    </form>
                      </div>
                  </div>
                </div>
                
                <!-- Clear Cache -->
                  <div class="col-md-6">
                    <div class="tool-card">
                      <div class="tool-icon bg-warning">
                    <i class="fas fa-broom"></i>
                  </div>
                  <div class="tool-content">
                        <h6>Clear Cache</h6>
                        <p class="text-muted mb-2">Clear system cache</p>
                    <form method="post" class="d-inline">
                      <?php echo csrf_input(); ?>
                      <input type="hidden" name="action" value="clear_cache">
                          <button class="btn btn-sm btn-warning w-100" type="submit">
                            <i class="fas fa-trash me-1"></i>Clear
                      </button>
                    </form>
                      </div>
                  </div>
                </div>
                
                <!-- Database Backup -->
                  <div class="col-md-6">
                    <div class="tool-card">
                      <div class="tool-icon bg-success">
                    <i class="fas fa-database"></i>
                  </div>
                  <div class="tool-content">
                    <h6>Database Backup</h6>
                        <p class="text-muted mb-2">Download backup</p>
                    <form method="post" class="d-inline">
                      <?php echo csrf_input(); ?>
                      <input type="hidden" name="action" value="export_json">
                          <button class="btn btn-sm btn-success w-100" type="submit">
                        <i class="fas fa-download me-1"></i>Backup
                      </button>
                    </form>
                      </div>
                  </div>
                </div>
                
                <!-- System Health -->
                  <div class="col-md-6">
                    <div class="tool-card">
                      <div class="tool-icon bg-info">
                    <i class="fas fa-heartbeat"></i>
                  </div>
                  <div class="tool-content">
                        <h6>Health Check</h6>
                        <p class="text-muted mb-2">Run diagnostics</p>
                    <form method="post" class="d-inline">
                      <?php echo csrf_input(); ?>
                      <input type="hidden" name="action" value="health_check">
                          <button class="btn btn-sm btn-info w-100" type="submit">
                            <i class="fas fa-stethoscope me-1"></i>Check
                      </button>
                    </form>
                  </div>
                </div>
                  </div>
                </div>
                
                <!-- Maintenance Mode -->
                <div class="mt-4">
                  <h6 class="mb-3">
                    <i class="fas fa-wrench me-2"></i>Maintenance Mode
                  </h6>
                  <div class="d-flex gap-2">
                    <form method="post" class="flex-fill">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="action" value="maintenance_on">
                      <button class="btn btn-outline-danger w-100" type="submit">
                        <i class="fas fa-toggle-on me-1"></i>Enable
                      </button>
                      </form>
                    <form method="post" class="flex-fill">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="action" value="maintenance_off">
                      <button class="btn btn-outline-secondary w-100" type="submit">
                        <i class="fas fa-toggle-off me-1"></i>Disable
                      </button>
                      </form>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </main>
  </div>
</div>

<!-- Package Edit Modal -->
<div class="modal fade" id="packageModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-gradient-success text-white">
        <h5 class="modal-title">
          <i class="fas fa-edit me-2"></i>
          <span id="modalTitle">Edit Package</span>
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="packageForm">
          <input type="hidden" id="packageId" name="package_id">
          <div class="mb-3">
            <label class="form-label fw-bold">Package Label</label>
            <input type="text" id="packageLabel" name="label" class="form-control" required>
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-bold">Price</label>
              <div class="input-group">
                <span class="input-group-text"><?php echo htmlspecialchars($settings['currency_code']); ?></span>
                <input type="number" id="packagePrice" name="price" class="form-control" step="0.01" min="0.01" required>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-bold">Square Meters</label>
              <div class="input-group">
                <input type="number" id="packageSqm" name="sqm_meters" class="form-control" step="0.01" min="0">
                <span class="input-group-text">m²</span>
              </div>
            </div>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-success" id="savePackageBtn">
          <i class="fas fa-save me-2"></i>Save Changes
        </button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script src="assets/settings.js"></script>
</body>
</html>
