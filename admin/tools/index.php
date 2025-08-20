<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_admin();

$db = db();
$page_title = 'Developer Tools';
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    try {
        if (in_array($action, ['reset_payments','reset_pledges','reset_chats','reset_audits','reset_all'], true)) {
            $db->begin_transaction();

            if ($action === 'reset_payments' || $action === 'reset_all') {
                // Clear payments and reset auto-increment
                $db->query('DELETE FROM payments');
                $db->query('ALTER TABLE payments AUTO_INCREMENT = 1');
            }

            if ($action === 'reset_pledges' || $action === 'reset_all') {
                // Remove dependent payments first to satisfy FK, then pledges
                $db->query('DELETE FROM payments');
                $db->query('ALTER TABLE payments AUTO_INCREMENT = 1');
                $db->query('DELETE FROM pledges');
                $db->query('ALTER TABLE pledges AUTO_INCREMENT = 1');
            }

            if ($action === 'reset_chats' || $action === 'reset_all') {
                $db->query('DELETE FROM message_attachments');
                $db->query('ALTER TABLE message_attachments AUTO_INCREMENT = 1');
                $db->query('DELETE FROM user_messages');
                $db->query('ALTER TABLE user_messages AUTO_INCREMENT = 1');
            }

            if ($action === 'reset_audits' || $action === 'reset_all') {
                $db->query('DELETE FROM audit_logs');
                $db->query('ALTER TABLE audit_logs AUTO_INCREMENT = 1');
            }

            // Reset counters to zeros (admin-approved projector totals)
            if (in_array($action, ['reset_payments','reset_pledges','reset_all'], true)) {
                $stmt = $db->prepare("INSERT INTO counters (id, paid_total, pledged_total, grand_total, version, recalc_needed)
                                       VALUES (1, 0, 0, 0, 1, 0)
                                       ON DUPLICATE KEY UPDATE paid_total=0, pledged_total=0, grand_total=0, version=1, recalc_needed=0");
                $stmt->execute();
            }

            $db->commit();
            if ($action === 'reset_payments') { $msg = 'Payments reset complete'; }
            elseif ($action === 'reset_pledges') { $msg = 'Pledges reset complete'; }
            elseif ($action === 'reset_chats') { $msg = 'Conversations reset complete'; }
            elseif ($action === 'reset_audits') { $msg = 'Audit logs reset complete'; }
            else { $msg = 'All sample data reset complete (pledges, payments, chats, audits, counters)'; }
        }
    } catch (Throwable $e) {
        if ($db->errno) { $db->rollback(); }
        $msg = 'Error: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Developer Tools - Fundraising Admin</title>
  <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="../assets/admin.css?v=<?php echo @filemtime(__DIR__ . '/../assets/admin.css'); ?>">
</head>
<body>
<div class="admin-wrapper">
  <?php include '../includes/sidebar.php'; ?>
  <div class="admin-content">
    <?php include '../includes/topbar.php'; ?>
    <main class="main-content">
      <div class="container-fluid">
        <div class="row">
          <div class="col-12">
            <?php if ($msg): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
              <i class="fas fa-info-circle me-2"></i><?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?>
              <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <div class="card border-danger">
              <div class="card-header bg-danger text-white d-flex align-items-center">
                <i class="fas fa-triangle-exclamation me-2"></i>
                Destructive Actions (Development Only)
              </div>
              <div class="card-body">
                <p class="mb-4">These actions permanently delete data and reset auto-increment IDs to 1. Projector counters are reset to 0. Use only in development. Settings, users, and donation packages are preserved.</p>

                <div class="d-flex flex-wrap gap-2">
                  <form method="post" onsubmit="return confirm('Reset PAYMENTS table and counters to zero?');">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="reset_payments">
                    <button class="btn btn-outline-danger">
                      <i class="fas fa-credit-card me-1"></i>Reset Payments
                    </button>
                  </form>

                  <form method="post" onsubmit="return confirm('Reset PLEDGES (and dependent PAYMENTS) and counters to zero?');">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="reset_pledges">
                    <button class="btn btn-outline-danger">
                      <i class="fas fa-hand-holding-usd me-1"></i>Reset Pledges
                    </button>
                  </form>

                  <form method="post" onsubmit="return confirm('Reset ALL (PLEDGES and PAYMENTS) and counters to zero?');">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="reset_all">
                    <button class="btn btn-danger">
                      <i class="fas fa-bomb me-1"></i>Reset All
                    </button>
                  </form>
                  
                  <form method="post" onsubmit="return confirm('Reset all conversations (direct messages) and attachments?');">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="reset_chats">
                    <button class="btn btn-outline-danger">
                      <i class="fas fa-comments me-1"></i>Reset Conversations
                    </button>
                  </form>

                  <form method="post" onsubmit="return confirm('Reset all audit logs?');">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="reset_audits">
                    <button class="btn btn-outline-danger">
                      <i class="fas fa-clipboard-list me-1"></i>Reset Audit Logs
                    </button>
                  </form>
                </div>
              </div>
            </div>

            <div class="col-md-6 col-lg-4 mb-4">
                <div class="card h-100">
                    <div class="card-body">
                        <h5 class="card-title"><i class="fas fa-microchip me-2 text-info"></i>Test Smart Allocation</h5>
                        <p class="card-text">Test the intelligent, space-filling allocation engine to see how it fills gaps sequentially.</p>
                        <a href="test_smart_allocation.php" class="btn btn-info">Run Test</a>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-lg-4 mb-4">
                <div class="card h-100 border-danger">
                    <div class="card-body">
                        <h5 class="card-title"><i class="fas fa-power-off me-2 text-danger"></i>Reset Floor Map</h5>
                        <p class="card-text">Wipe all allocation data from the floor map. <strong class="text-danger">This is irreversible.</strong></p>
                        <a href="reset_floor_map.php" class="btn btn-danger">Reset Map</a>
                    </div>
                </div>
            </div>

        </div>
    </div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js?v=<?php echo @filemtime(__DIR__ . '/../assets/admin.js'); ?>"></script>
</body>
</html>


