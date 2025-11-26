<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../shared/auth.php';
require_once __DIR__ . '/../../../shared/csrf.php';
require_once __DIR__ . '/../../../config/db.php';
require_login();
require_admin();

$page_title = 'SMS Blacklist';
$current_user = current_user();
$db = db();

$blacklist = [];
$error_message = null;
$success_message = $_SESSION['success_message'] ?? null;
unset($_SESSION['success_message']);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'add') {
            $phone = preg_replace('/[^0-9+]/', '', trim($_POST['phone_number'] ?? ''));
            $reason = $_POST['reason'] ?? 'admin_blocked';
            $details = trim($_POST['details'] ?? '');
            $donor_id = null;
            
            if (empty($phone)) {
                throw new Exception('Phone number is required.');
            }
            
            // Check if already exists
            $check = $db->prepare("SELECT id FROM sms_blacklist WHERE phone_number = ?");
            $check->bind_param('s', $phone);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                throw new Exception('This number is already blacklisted.');
            }
            
            // Find donor if exists
            $donor_check = $db->prepare("SELECT id FROM donors WHERE phone = ?");
            $donor_check->bind_param('s', $phone);
            $donor_check->execute();
            if ($row = $donor_check->get_result()->fetch_assoc()) {
                $donor_id = $row['id'];
                // Also update donor's opt-in status
                $db->query("UPDATE donors SET sms_opt_in = 0 WHERE id = $donor_id");
            }
            
            $stmt = $db->prepare("
                INSERT INTO sms_blacklist (phone_number, donor_id, reason, details, blocked_by, blocked_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->bind_param('sissi', $phone, $donor_id, $reason, $details, $current_user['id']);
            $stmt->execute();
            
            $_SESSION['success_message'] = 'Number added to blacklist.';
            header('Location: blacklist.php');
            exit;
        }
        
        if ($action === 'remove') {
            $id = (int)$_POST['blacklist_id'];
            
            // Get phone before deleting
            $get = $db->prepare("SELECT phone_number, donor_id FROM sms_blacklist WHERE id = ?");
            $get->bind_param('i', $id);
            $get->execute();
            $row = $get->get_result()->fetch_assoc();
            
            // Remove from blacklist
            $stmt = $db->prepare("DELETE FROM sms_blacklist WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            
            // Optionally re-enable SMS for donor
            if ($row && $row['donor_id']) {
                $db->query("UPDATE donors SET sms_opt_in = 1 WHERE id = " . (int)$row['donor_id']);
            }
            
            $_SESSION['success_message'] = 'Number removed from blacklist.';
            header('Location: blacklist.php');
            exit;
        }
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// Get blacklist
try {
    $result = $db->query("
        SELECT b.*, d.name as donor_name, u.name as blocked_by_name
        FROM sms_blacklist b
        LEFT JOIN donors d ON b.donor_id = d.id
        LEFT JOIN users u ON b.blocked_by = u.id
        ORDER BY b.blocked_at DESC
    ");
    while ($row = $result->fetch_assoc()) {
        $blacklist[] = $row;
    }
} catch (Exception $e) {
    $error_message = $e->getMessage();
}

$reasons = [
    'opt_out' => 'Opt-Out Request',
    'invalid_number' => 'Invalid Number',
    'spam_complaint' => 'Spam Complaint',
    'hard_bounce' => 'Hard Bounce',
    'legal_request' => 'Legal/GDPR Request',
    'admin_blocked' => 'Admin Blocked',
    'deceased' => 'Deceased'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $page_title; ?> - Fundraising Admin</title>
    <link rel="icon" type="image/svg+xml" href="../../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../../assets/admin.css">
    <link rel="stylesheet" href="../assets/donor-management.css">
</head>
<body>
<div class="admin-wrapper">
    <?php include '../../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="container-fluid p-3 p-md-4">
                
                <!-- Header -->
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 gap-3">
                    <div>
                        <nav aria-label="breadcrumb">
                            <ol class="breadcrumb mb-2">
                                <li class="breadcrumb-item"><a href="index.php">SMS Dashboard</a></li>
                                <li class="breadcrumb-item active">Blacklist</li>
                            </ol>
                        </nav>
                        <h1 class="h3 mb-0">
                            <i class="fas fa-ban text-danger me-2"></i>SMS Blacklist
                        </h1>
                    </div>
                    <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#addModal">
                        <i class="fas fa-plus me-2"></i>Block Number
                    </button>
                </div>
                
                <?php if ($error_message): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($success_message): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Blacklist -->
                <?php if (empty($blacklist)): ?>
                    <div class="card">
                        <div class="card-body text-center py-5">
                            <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                            <h4>No Blocked Numbers</h4>
                            <p class="text-muted">All phone numbers can receive SMS.</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Phone Number</th>
                                        <th>Donor</th>
                                        <th>Reason</th>
                                        <th class="d-none d-md-table-cell">Blocked By</th>
                                        <th class="d-none d-lg-table-cell">Date</th>
                                        <th style="width: 80px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($blacklist as $item): ?>
                                        <tr>
                                            <td>
                                                <code><?php echo htmlspecialchars($item['phone_number']); ?></code>
                                            </td>
                                            <td>
                                                <?php if ($item['donor_name']): ?>
                                                    <a href="../view-donor.php?id=<?php echo $item['donor_id']; ?>">
                                                        <?php echo htmlspecialchars($item['donor_name']); ?>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php 
                                                    echo match($item['reason']) {
                                                        'opt_out', 'legal_request' => 'warning',
                                                        'invalid_number', 'hard_bounce' => 'secondary',
                                                        'spam_complaint' => 'danger',
                                                        'deceased' => 'dark',
                                                        default => 'info'
                                                    };
                                                ?>">
                                                    <?php echo $reasons[$item['reason']] ?? $item['reason']; ?>
                                                </span>
                                            </td>
                                            <td class="d-none d-md-table-cell">
                                                <?php echo htmlspecialchars($item['blocked_by_name'] ?? 'System'); ?>
                                            </td>
                                            <td class="d-none d-lg-table-cell">
                                                <?php echo date('M j, Y', strtotime($item['blocked_at'])); ?>
                                            </td>
                                            <td>
                                                <form method="POST" class="d-inline" 
                                                      onsubmit="return confirm('Remove this number from blacklist?');">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="remove">
                                                    <input type="hidden" name="blacklist_id" value="<?php echo $item['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-success" title="Unblock">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
                
            </div>
        </main>
    </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="add">
                
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-ban text-danger me-2"></i>Block Phone Number</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Phone Number <span class="text-danger">*</span></label>
                        <input type="tel" name="phone_number" class="form-control" required
                               placeholder="07XXX XXX XXX">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Reason</label>
                        <select name="reason" class="form-select">
                            <?php foreach ($reasons as $key => $label): ?>
                                <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Details (Optional)</label>
                        <textarea name="details" class="form-control" rows="2" 
                                  placeholder="Additional notes..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Block Number</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/admin.js"></script>
</body>
</html>

