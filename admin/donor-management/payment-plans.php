<?php
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../includes/resilient_db_loader.php';

require_login();
require_admin();

$page_title = 'Payment Plan Templates';
$current_user = current_user();
$db = db();

$success_message = '';
$error_message = '';

// Check if payment_plan_templates table exists, if not create it
if ($db_connection_ok) {
    $table_check = $db->query("SHOW TABLES LIKE 'payment_plan_templates'");
    if ($table_check->num_rows === 0) {
        $create_table = "CREATE TABLE IF NOT EXISTS payment_plan_templates (
            id INT PRIMARY KEY AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            duration_months INT NOT NULL,
            suggested_monthly_amount DECIMAL(10,2) NULL COMMENT 'Optional suggested amount per month',
            is_active TINYINT(1) DEFAULT 1,
            is_default TINYINT(1) DEFAULT 0,
            sort_order INT DEFAULT 0,
            created_by_user_id INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_active (is_active),
            INDEX idx_sort (sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $db->query($create_table);
        
        // Insert default templates
        $defaults = [
            ['3-Month Plan', 'Pay your pledge in 3 monthly installments', 3, 0],
            ['6-Month Plan', 'Pay your pledge in 6 monthly installments', 6, 1],
            ['12-Month Plan', 'Pay your pledge in 12 monthly installments', 12, 0],
            ['18-Month Plan', 'Pay your pledge in 18 monthly installments', 18, 0],
            ['24-Month Plan', 'Pay your pledge in 24 monthly installments', 24, 0],
            ['Custom Plan', 'Create a custom payment schedule', 0, 0]
        ];
        
        $sort = 1;
        foreach ($defaults as $default) {
            $stmt = $db->prepare("INSERT INTO payment_plan_templates (name, description, duration_months, is_default, sort_order) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param('ssiii', $default[0], $default[1], $default[2], $default[3], $sort);
            $stmt->execute();
            $sort++;
        }
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db_connection_ok) {
    verify_csrf();
    
    $action = $_POST['action'] ?? '';
    
    try {
        $db->begin_transaction();
        
        if ($action === 'create_template') {
            // Create new payment plan template
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $duration_months = (int)($_POST['duration_months'] ?? 0);
            $suggested_monthly_amount = isset($_POST['suggested_monthly_amount']) && $_POST['suggested_monthly_amount'] !== '' 
                ? (float)$_POST['suggested_monthly_amount'] 
                : null;
            $is_default = isset($_POST['is_default']) ? 1 : 0;
            
            // Validation
            if (empty($name)) {
                throw new Exception('Plan name is required');
            }
            if ($duration_months < 0 || $duration_months > 60) {
                throw new Exception('Duration must be between 0 and 60 months (0 for custom)');
            }
            
            // If setting as default, unset other defaults
            if ($is_default) {
                $db->query("UPDATE payment_plan_templates SET is_default = 0");
            }
            
            // Get next sort order
            $sort_result = $db->query("SELECT COALESCE(MAX(sort_order), 0) + 1 as next_sort FROM payment_plan_templates");
            $next_sort = $sort_result->fetch_assoc()['next_sort'];
            
            // Insert template
            $stmt = $db->prepare("
                INSERT INTO payment_plan_templates (
                    name, description, duration_months, suggested_monthly_amount,
                    is_default, sort_order, created_by_user_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param('ssdidii', $name, $description, $duration_months, 
                $suggested_monthly_amount, $is_default, $next_sort, $current_user['id']);
            $stmt->execute();
            $template_id = $db->insert_id;
            
            // Audit log
            $audit_data = json_encode([
                'name' => $name,
                'duration_months' => $duration_months,
                'is_default' => $is_default
            ], JSON_UNESCAPED_SLASHES);
            $audit = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, after_json, source) VALUES(?, 'payment_plan_template', ?, 'create', ?, 'admin')");
            $audit->bind_param('iis', $current_user['id'], $template_id, $audit_data);
            $audit->execute();
            
            $db->commit();
            $_SESSION['success_message'] = "Payment plan template '{$name}' created successfully!";
            header('Location: payment-plans.php');
            exit;
            
        } elseif ($action === 'edit_template') {
            // Edit existing template
            $template_id = (int)($_POST['template_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $duration_months = (int)($_POST['duration_months'] ?? 0);
            $suggested_monthly_amount = isset($_POST['suggested_monthly_amount']) && $_POST['suggested_monthly_amount'] !== '' 
                ? (float)$_POST['suggested_monthly_amount'] 
                : null;
            $is_default = isset($_POST['is_default']) ? 1 : 0;
            
            if (empty($name)) {
                throw new Exception('Plan name is required');
            }
            
            // If setting as default, unset other defaults
            if ($is_default) {
                $db->query("UPDATE payment_plan_templates SET is_default = 0");
            }
            
            // Update template
            $stmt = $db->prepare("
                UPDATE payment_plan_templates 
                SET name = ?, description = ?, duration_months = ?, 
                    suggested_monthly_amount = ?, is_default = ?
                WHERE id = ?
            ");
            $stmt->bind_param('ssdidi', $name, $description, $duration_months, 
                $suggested_monthly_amount, $is_default, $template_id);
            $stmt->execute();
            
            // Audit log
            $audit_data = json_encode([
                'name' => $name,
                'duration_months' => $duration_months
            ], JSON_UNESCAPED_SLASHES);
            $audit = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, after_json, source) VALUES(?, 'payment_plan_template', ?, 'update', ?, 'admin')");
            $audit->bind_param('iis', $current_user['id'], $template_id, $audit_data);
            $audit->execute();
            
            $db->commit();
            $_SESSION['success_message'] = "Payment plan template updated successfully!";
            header('Location: payment-plans.php');
            exit;
            
        } elseif ($action === 'toggle_active') {
            $template_id = (int)($_POST['template_id'] ?? 0);
            $is_active = (int)($_POST['is_active'] ?? 0);
            
            $stmt = $db->prepare("UPDATE payment_plan_templates SET is_active = ? WHERE id = ?");
            $stmt->bind_param('ii', $is_active, $template_id);
            $stmt->execute();
            
            // Audit log
            $audit = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, after_json, source) VALUES(?, 'payment_plan_template', ?, 'toggle_active', ?, 'admin')");
            $audit_data = json_encode(['is_active' => $is_active], JSON_UNESCAPED_SLASHES);
            $audit->bind_param('iis', $current_user['id'], $template_id, $audit_data);
            $audit->execute();
            
            $db->commit();
            $_SESSION['success_message'] = $is_active ? "Plan activated" : "Plan deactivated";
            header('Location: payment-plans.php');
            exit;
            
        } elseif ($action === 'delete_template') {
            $template_id = (int)($_POST['template_id'] ?? 0);
            
            $stmt = $db->prepare("DELETE FROM payment_plan_templates WHERE id = ?");
            $stmt->bind_param('i', $template_id);
            $stmt->execute();
            
            // Audit log
            $audit = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, source) VALUES(?, 'payment_plan_template', ?, 'delete', 'admin')");
            $audit->bind_param('ii', $current_user['id'], $template_id);
            $audit->execute();
            
            $db->commit();
            $_SESSION['success_message'] = "Payment plan template deleted successfully!";
            header('Location: payment-plans.php');
            exit;
            
        } elseif ($action === 'reorder') {
            $order = json_decode($_POST['order'] ?? '[]', true);
            foreach ($order as $index => $id) {
                $stmt = $db->prepare("UPDATE payment_plan_templates SET sort_order = ? WHERE id = ?");
                $stmt->bind_param('ii', $index, $id);
                $stmt->execute();
            }
            
            $db->commit();
            echo json_encode(['success' => true]);
            exit;
        }
        
    } catch (Exception $e) {
        $db->rollback();
        $error_message = $e->getMessage();
    }
}

// Check for success message from redirect
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// Load all payment plan templates
$templates = [];
if ($db_connection_ok) {
    try {
        $result = $db->query("SELECT * FROM payment_plan_templates ORDER BY sort_order ASC, created_at ASC");
        if ($result) {
            $templates = $result->fetch_all(MYSQLI_ASSOC);
        }
    } catch (Exception $e) {
        // Silent fail
    }
}

// Calculate statistics
$stats = [
    'total_templates' => count($templates),
    'active_templates' => count(array_filter($templates, fn($t) => $t['is_active'])),
    'inactive_templates' => count(array_filter($templates, fn($t) => !$t['is_active'])),
    'default_template' => ''
];

foreach ($templates as $t) {
    if ($t['is_default']) {
        $stats['default_template'] = $t['name'];
        break;
    }
}
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
    <link rel="stylesheet" href="../assets/admin.css">
    <link rel="stylesheet" href="assets/donor-management.css">
    <style>
    .plan-card {
        transition: all 0.3s ease;
        cursor: move;
    }
    .plan-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.15) !important;
    }
    .plan-card.dragging {
        opacity: 0.5;
    }
    .default-badge {
        position: absolute;
        top: -10px;
        right: 10px;
    }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="container-fluid">
                <?php include '../includes/db_error_banner.php'; ?>
                
                <!-- Page Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 mb-1 text-primary">
                            <i class="fas fa-th-list me-2"></i>Payment Plan Templates
                        </h1>
                        <p class="text-muted mb-0">Create and manage payment plan options that donors can choose from</p>
                    </div>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createTemplateModal">
                        <i class="fas fa-plus me-2"></i>Create Template
                    </button>
                </div>

                <?php if ($success_message): ?>
                    <div class="alert alert-success alert-dismissible fade show animate-fade-in">
                        <i class="fas fa-check-circle me-2"></i>
                        <?php echo htmlspecialchars($success_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                    <div class="alert alert-danger alert-dismissible fade show animate-fade-in">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <?php echo htmlspecialchars($error_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Summary Statistics -->
                <div class="row g-4 mb-4">
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="stat-card animate-fade-in" style="color: #0a6286;">
                            <div class="stat-icon bg-primary">
                                <i class="fas fa-th-list"></i>
                            </div>
                            <div class="stat-content">
                                <h3 class="stat-value"><?php echo $stats['total_templates']; ?></h3>
                                <p class="stat-label">Total Templates</p>
                                <div class="stat-trend text-primary">
                                    <i class="fas fa-layer-group"></i> Available
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="stat-card animate-fade-in" style="animation-delay: 0.1s; color: #0d7f4d;">
                            <div class="stat-icon bg-success">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <div class="stat-content">
                                <h3 class="stat-value"><?php echo $stats['active_templates']; ?></h3>
                                <p class="stat-label">Active Plans</p>
                                <div class="stat-trend text-success">
                                    <i class="fas fa-toggle-on"></i> Enabled
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="stat-card animate-fade-in" style="animation-delay: 0.2s; color: #6c757d;">
                            <div class="stat-icon bg-secondary">
                                <i class="fas fa-toggle-off"></i>
                            </div>
                            <div class="stat-content">
                                <h3 class="stat-value"><?php echo $stats['inactive_templates']; ?></h3>
                                <p class="stat-label">Inactive Plans</p>
                                <div class="stat-trend text-secondary">
                                    <i class="fas fa-pause"></i> Disabled
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="stat-card animate-fade-in" style="animation-delay: 0.3s; color: #b88a1a;">
                            <div class="stat-icon bg-warning">
                                <i class="fas fa-star"></i>
                            </div>
                            <div class="stat-content">
                                <h3 class="stat-value" style="font-size: 1rem;"><?php echo $stats['default_template'] ?: 'None'; ?></h3>
                                <p class="stat-label">Default Plan</p>
                                <div class="stat-trend text-warning">
                                    <i class="fas fa-crown"></i> Recommended
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Info Alert -->
                <div class="alert alert-info border-info animate-fade-in" style="animation-delay: 0.4s;">
                    <div class="d-flex">
                        <div class="me-3">
                            <i class="fas fa-info-circle fa-2x"></i>
                        </div>
                        <div>
                            <h6 class="alert-heading mb-2">How Payment Plan Templates Work</h6>
                            <p class="mb-2">Create templates that donors can choose from when setting up their payment schedule. For example:</p>
                            <ul class="mb-0">
                                <li><strong>6-Month Plan:</strong> Donors can spread their pledge over 6 equal monthly payments</li>
                                <li><strong>12-Month Plan:</strong> Donors can pay their pledge over a full year</li>
                                <li><strong>Custom Plan:</strong> Allow donors to create their own custom schedule</li>
                            </ul>
                            <p class="mt-2 mb-0"><i class="fas fa-lightbulb me-1"></i><strong>Tip:</strong> Drag and drop cards to reorder. Mark one as "default" to recommend it to donors.</p>
                        </div>
                    </div>
                </div>

                <!-- Payment Plan Templates Grid -->
                <div class="row g-4" id="templatesGrid">
                    <?php foreach ($templates as $template): ?>
                    <div class="col-12 col-md-6 col-lg-4" data-template-id="<?php echo $template['id']; ?>">
                        <div class="plan-card card border-0 shadow-sm h-100 position-relative animate-fade-in" 
                             style="animation-delay: <?php echo (array_search($template, $templates) * 0.1); ?>s;">
                            
                            <?php if ($template['is_default']): ?>
                            <span class="badge bg-warning default-badge">
                                <i class="fas fa-star me-1"></i>Default
                            </span>
                            <?php endif; ?>
                            
                            <div class="card-header bg-<?php echo $template['is_active'] ? 'primary' : 'secondary'; ?> text-white">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">
                                        <i class="fas fa-grip-vertical me-2 drag-handle" style="cursor: move;"></i>
                                        <?php echo htmlspecialchars($template['name']); ?>
                                    </h5>
                                    <span class="badge bg-light text-dark">
                                        <?php if ($template['duration_months'] == 0): ?>
                                            Custom
                                        <?php else: ?>
                                            <?php echo $template['duration_months']; ?> months
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="card-body">
                                <p class="card-text text-muted">
                                    <?php echo htmlspecialchars($template['description'] ?: 'No description'); ?>
                                </p>
                                
                                <?php if ($template['suggested_monthly_amount']): ?>
                                <div class="border-start border-4 border-success ps-3 mb-3">
                                    <small class="text-muted d-block">Suggested Monthly Amount</small>
                                    <h5 class="mb-0 text-success">£<?php echo number_format($template['suggested_monthly_amount'], 2); ?></h5>
                                </div>
                                <?php endif; ?>
                                
                                <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" 
                                               id="active_<?php echo $template['id']; ?>"
                                               <?php echo $template['is_active'] ? 'checked' : ''; ?>
                                               onchange="toggleActive(<?php echo $template['id']; ?>, this.checked)">
                                        <label class="form-check-label" for="active_<?php echo $template['id']; ?>">
                                            <?php echo $template['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </label>
                                    </div>
                                    
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-outline-primary edit-template" 
                                                data-template='<?php echo htmlspecialchars(json_encode($template), ENT_QUOTES); ?>'>
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-danger delete-template" 
                                                data-id="<?php echo $template['id']; ?>"
                                                data-name="<?php echo htmlspecialchars($template['name']); ?>">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php if (empty($templates)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-th-list fa-4x text-muted mb-3"></i>
                    <h5 class="text-muted">No payment plan templates yet</h5>
                    <p class="text-muted">Create your first template to get started</p>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createTemplateModal">
                        <i class="fas fa-plus me-2"></i>Create Your First Template
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>

<!-- Create Template Modal -->
<div class="modal fade" id="createTemplateModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST" action="" id="createTemplateForm">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="create_template">
                
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Create Payment Plan Template</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body p-4">
                    <div class="row g-4">
                        <div class="col-12">
                            <label class="form-label fw-bold">Plan Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-lg" name="name" 
                                   placeholder="e.g., 6-Month Plan" required>
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label fw-bold">Description</label>
                            <textarea class="form-control" name="description" rows="3" 
                                      placeholder="Describe this payment plan option..."></textarea>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Duration (Months) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control form-control-lg" name="duration_months" 
                                   min="0" max="60" placeholder="6" required>
                            <small class="text-muted">Enter 0 for custom/flexible duration</small>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Suggested Monthly Amount (Optional)</label>
                            <div class="input-group input-group-lg">
                                <span class="input-group-text">£</span>
                                <input type="number" class="form-control" name="suggested_monthly_amount" 
                                       min="0" step="0.01" placeholder="Leave blank">
                            </div>
                        </div>
                        
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_default" id="is_default_create">
                                <label class="form-check-label fw-bold" for="is_default_create">
                                    <i class="fas fa-star text-warning me-1"></i>
                                    Set as Default (Recommended to donors)
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="fas fa-save me-2"></i>Create Template
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Template Modal -->
<div class="modal fade" id="editTemplateModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST" action="" id="editTemplateForm">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="edit_template">
                <input type="hidden" name="template_id" id="edit_template_id">
                
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Payment Plan Template</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body p-4">
                    <div class="row g-4">
                        <div class="col-12">
                            <label class="form-label fw-bold">Plan Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-lg" name="name" id="edit_name" required>
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label fw-bold">Description</label>
                            <textarea class="form-control" name="description" id="edit_description" rows="3"></textarea>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Duration (Months) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control form-control-lg" name="duration_months" 
                                   id="edit_duration_months" min="0" max="60" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Suggested Monthly Amount</label>
                            <div class="input-group input-group-lg">
                                <span class="input-group-text">£</span>
                                <input type="number" class="form-control" name="suggested_monthly_amount" 
                                       id="edit_suggested_monthly_amount" min="0" step="0.01">
                            </div>
                        </div>
                        
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_default" id="edit_is_default">
                                <label class="form-check-label fw-bold" for="edit_is_default">
                                    <i class="fas fa-star text-warning me-1"></i>
                                    Set as Default
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="fas fa-save me-2"></i>Update Template
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script src="../assets/admin.js"></script>

<script>
// Initialize drag-and-drop sorting
new Sortable(document.getElementById('templatesGrid'), {
    animation: 150,
    handle: '.drag-handle',
    ghostClass: 'dragging',
    onEnd: function(evt) {
        // Get new order
        const order = [];
        $('#templatesGrid > div').each(function() {
            order.push($(this).data('template-id'));
        });
        
        // Save to server
        $.post('', {
            action: 'reorder',
            order: JSON.stringify(order),
            <?php echo str_replace(['"', "'"], '', csrf_input()); ?>
        });
    }
});

// Toggle active status
function toggleActive(templateId, isActive) {
    const form = $('<form>', {
        method: 'POST',
        action: ''
    });
    form.append('<?php echo csrf_input(); ?>');
    form.append($('<input>', { type: 'hidden', name: 'action', value: 'toggle_active' }));
    form.append($('<input>', { type: 'hidden', name: 'template_id', value: templateId }));
    form.append($('<input>', { type: 'hidden', name: 'is_active', value: isActive ? 1 : 0 }));
    $('body').append(form);
    form.submit();
}

// Edit template
$(document).on('click', '.edit-template', function() {
    const template = JSON.parse($(this).attr('data-template'));
    
    $('#edit_template_id').val(template.id);
    $('#edit_name').val(template.name);
    $('#edit_description').val(template.description);
    $('#edit_duration_months').val(template.duration_months);
    $('#edit_suggested_monthly_amount').val(template.suggested_monthly_amount);
    $('#edit_is_default').prop('checked', template.is_default == 1);
    
    $('#editTemplateModal').modal('show');
});

// Delete template
$(document).on('click', '.delete-template', function() {
    const id = $(this).data('id');
    const name = $(this).data('name');
    
    if (confirm(`Are you sure you want to delete "${name}"? This action cannot be undone.`)) {
        const form = $('<form>', {
            method: 'POST',
            action: ''
        });
        form.append('<?php echo csrf_input(); ?>');
        form.append($('<input>', { type: 'hidden', name: 'action', value: 'delete_template' }));
        form.append($('<input>', { type: 'hidden', name: 'template_id', value: id }));
        $('body').append(form);
        form.submit();
    }
});
</script>
</body>
</html>
