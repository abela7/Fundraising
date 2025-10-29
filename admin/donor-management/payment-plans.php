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
            $stmt->bind_param('ssidiiii', 
                $name, 
                $description, 
                $duration_months,  // int
                $suggested_monthly_amount,  // double (nullable)
                $is_default,  // int
                $next_sort,  // int
                $current_user['id']  // int
            );
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
            
            if ($template_id <= 0) {
                throw new Exception('Invalid template ID');
            }
            
            if (empty($name)) {
                throw new Exception('Plan name is required');
            }
            
            if ($duration_months < 0 || $duration_months > 60) {
                throw new Exception('Duration must be between 0 and 60 months');
            }
            
            // Get current state for audit
            $get_stmt = $db->prepare("SELECT name, description, duration_months, suggested_monthly_amount, is_default FROM payment_plan_templates WHERE id = ?");
            $get_stmt->bind_param('i', $template_id);
            $get_stmt->execute();
            $current = $get_stmt->get_result()->fetch_assoc();
            if (!$current) {
                throw new Exception('Template not found');
            }
            
            // If setting as default, unset other defaults
            if ($is_default && !$current['is_default']) {
                $db->query("UPDATE payment_plan_templates SET is_default = 0 WHERE id != $template_id");
            }
            
            // Update template
            $stmt = $db->prepare("
                UPDATE payment_plan_templates 
                SET name = ?, description = ?, duration_months = ?, 
                    suggested_monthly_amount = ?, is_default = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->bind_param('ssidii', 
                $name,  // string
                $description,  // string
                $duration_months,  // int
                $suggested_monthly_amount,  // double (nullable)
                $is_default,  // int
                $template_id  // int
            );
            $stmt->execute();
            
            if ($stmt->affected_rows === 0) {
                throw new Exception('No changes detected or template not found');
            }
            
            // Audit log
            $before_data = json_encode([
                'name' => $current['name'],
                'description' => $current['description'],
                'duration_months' => (int)$current['duration_months'],
                'suggested_monthly_amount' => $current['suggested_monthly_amount'],
                'is_default' => (int)$current['is_default']
            ], JSON_UNESCAPED_SLASHES);
            $after_data = json_encode([
                'name' => $name,
                'description' => $description,
                'duration_months' => $duration_months,
                'suggested_monthly_amount' => $suggested_monthly_amount,
                'is_default' => $is_default
            ], JSON_UNESCAPED_SLASHES);
            $audit = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'payment_plan_template', ?, 'update', ?, ?, 'admin')");
            $audit->bind_param('iiss', $current_user['id'], $template_id, $before_data, $after_data);
            $audit->execute();
            
            $db->commit();
            $_SESSION['success_message'] = "Payment plan template '{$name}' updated successfully!";
            header('Location: payment-plans.php');
            exit;
            
        } elseif ($action === 'toggle_active') {
            $template_id = (int)($_POST['template_id'] ?? 0);
            $is_active = (int)($_POST['is_active'] ?? 0);
            
            if ($template_id <= 0) {
                throw new Exception('Invalid template ID');
            }
            
            // Get current state for audit
            $get_stmt = $db->prepare("SELECT name, is_active FROM payment_plan_templates WHERE id = ?");
            $get_stmt->bind_param('i', $template_id);
            $get_stmt->execute();
            $current = $get_stmt->get_result()->fetch_assoc();
            if (!$current) {
                throw new Exception('Template not found');
            }
            
            // Update status
            $stmt = $db->prepare("UPDATE payment_plan_templates SET is_active = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param('ii', $is_active, $template_id);
            $stmt->execute();
            
            // Audit log
            $before_data = json_encode(['is_active' => (int)$current['is_active']], JSON_UNESCAPED_SLASHES);
            $after_data = json_encode(['is_active' => $is_active], JSON_UNESCAPED_SLASHES);
            $audit = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'payment_plan_template', ?, 'toggle_active', ?, ?, 'admin')");
            $audit->bind_param('iiss', $current_user['id'], $template_id, $before_data, $after_data);
            $audit->execute();
            
            $db->commit();
            $_SESSION['success_message'] = $is_active ? "Plan '{$current['name']}' activated successfully" : "Plan '{$current['name']}' deactivated successfully";
            header('Location: payment-plans.php');
            exit;
            
        } elseif ($action === 'delete_template') {
            $template_id = (int)($_POST['template_id'] ?? 0);
            
            if ($template_id <= 0) {
                throw new Exception('Invalid template ID');
            }
            
            // Get template info for audit
            $get_stmt = $db->prepare("SELECT name, duration_months, is_default FROM payment_plan_templates WHERE id = ?");
            $get_stmt->bind_param('i', $template_id);
            $get_stmt->execute();
            $template = $get_stmt->get_result()->fetch_assoc();
            if (!$template) {
                throw new Exception('Template not found');
            }
            
            // Check if it's the default template
            if ($template['is_default']) {
                throw new Exception('Cannot delete the default template. Please set another template as default first.');
            }
            
            // Delete template
            $stmt = $db->prepare("DELETE FROM payment_plan_templates WHERE id = ?");
            $stmt->bind_param('i', $template_id);
            $stmt->execute();
            
            if ($stmt->affected_rows === 0) {
                throw new Exception('Failed to delete template');
            }
            
            // Audit log
            $before_data = json_encode([
                'name' => $template['name'],
                'duration_months' => (int)$template['duration_months']
            ], JSON_UNESCAPED_SLASHES);
            $audit = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, source) VALUES(?, 'payment_plan_template', ?, 'delete', ?, 'admin')");
            $audit->bind_param('iis', $current_user['id'], $template_id, $before_data);
            $audit->execute();
            
            $db->commit();
            $_SESSION['success_message'] = "Payment plan template '{$template['name']}' deleted successfully!";
            header('Location: payment-plans.php');
            exit;
            
        } elseif ($action === 'reorder') {
            $order = json_decode($_POST['order'] ?? '[]', true);
            if (!is_array($order)) {
                throw new Exception('Invalid order data');
            }
            
            foreach ($order as $index => $id) {
                $templateId = (int)$id;
                $sortOrder = (int)$index;
                $stmt = $db->prepare("UPDATE payment_plan_templates SET sort_order = ? WHERE id = ?");
                $stmt->bind_param('ii', $sortOrder, $templateId);
                $stmt->execute();
            }
            
            $db->commit();
            header('Content-Type: application/json');
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

// Load all payment plan templates (for display grid)
$templates_all = [];
if ($db_connection_ok) {
    try {
        $result = $db->query("SELECT * FROM payment_plan_templates ORDER BY sort_order ASC, created_at ASC");
        if ($result) {
            $templates_all = $result->fetch_all(MYSQLI_ASSOC);
        }
    } catch (Exception $e) {
        // Silent fail
    }
}

// Load active templates for preview modal dropdown
$templates = [];
if ($db_connection_ok) {
    try {
        $result = $db->query("SELECT * FROM payment_plan_templates WHERE is_active = 1 ORDER BY sort_order ASC, created_at ASC");
        if ($result) {
            $templates = $result->fetch_all(MYSQLI_ASSOC);
        }
    } catch (Exception $e) {
        // Silent fail
    }
}

// Load donors with outstanding balances for preview modal
$donors_for_preview = [];
if ($db_connection_ok) {
    try {
        // Get pledge donors with outstanding balances
        $check_column = $db->query("SHOW COLUMNS FROM donors LIKE 'donor_type'");
        $has_donor_type = $check_column && $check_column->num_rows > 0;
        $pledge_filter = $has_donor_type ? "donor_type = 'pledge'" : "total_pledged > 0";
        
        $result = $db->query("
            SELECT 
                id, name, phone, total_pledged, total_paid, balance
            FROM donors 
            WHERE {$pledge_filter} AND balance > 0
            ORDER BY name ASC
        ");
        if ($result) {
            $donors_for_preview = $result->fetch_all(MYSQLI_ASSOC);
        }
    } catch (Exception $e) {
        // Silent fail
    }
}

// Calculate statistics
$stats = [
    'total_templates' => count($templates_all),
    'active_templates' => count(array_filter($templates_all, fn($t) => $t['is_active'])),
    'inactive_templates' => count(array_filter($templates_all, fn($t) => !$t['is_active'])),
    'default_template' => ''
];

foreach ($templates_all as $t) {
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
        box-shadow: 0 4px 12px rgba(0,0,0,0.1) !important;
    }
    .plan-card.dragging {
        opacity: 0.5;
    }
    .plan-card .card-header {
        padding: 1.25rem 1.5rem;
    }
    .plan-card .card-body {
        padding: 1.5rem;
    }
    .duration-badge {
        font-size: 0.8rem;
        font-weight: 600;
        padding: 0.35rem 0.75rem;
    }
    .drag-handle {
        opacity: 0.3;
        transition: opacity 0.3s;
        cursor: move;
    }
    .plan-card:hover .drag-handle {
        opacity: 0.7;
    }
    .plan-description {
        min-height: 40px;
        line-height: 1.6;
    }
    .plan-actions {
        padding-top: 1rem;
        margin-top: 1rem;
        border-top: 1px solid #e9ecef;
    }
    
    /* Preview Plan Modal Styles */
    #previewPlanModal .table-responsive {
        border-radius: 0.375rem;
    }
    
    #previewPlanModal .table thead th {
        position: sticky;
        top: 0;
        background-color: #f8f9fa;
        z-index: 10;
    }
    
    #previewPlanModal .border-start {
        border-width: 3px !important;
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
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#previewPlanModal">
                            <i class="fas fa-calculator me-2"></i>Preview Plan
                        </button>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createTemplateModal">
                            <i class="fas fa-plus me-2"></i>Create Template
                        </button>
                    </div>
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

                <!-- Payment Plan Templates Grid -->
                <div class="row g-4 mb-4" id="templatesGrid">
                    <?php foreach ($templates_all as $index => $template): ?>
                    <div class="col-12 col-md-6 col-lg-4" data-template-id="<?php echo $template['id']; ?>">
                        <div class="plan-card card border-0 shadow-sm h-100 position-relative animate-fade-in" 
                             style="animation-delay: <?php echo ($index * 0.05); ?>s;">
                            
                            <div class="card-header bg-white border-bottom">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-grip-vertical me-2 drag-handle text-muted"></i>
                                        <h5 class="mb-0">
                                            <i class="fas fa-calendar-check text-<?php echo $template['is_active'] ? 'primary' : 'secondary'; ?> me-2"></i>
                                            <?php echo htmlspecialchars($template['name']); ?>
                                        </h5>
                                    </div>
                                    <span class="badge bg-<?php echo $template['is_active'] ? 'primary' : 'secondary'; ?> text-white duration-badge">
                                        <?php if ($template['duration_months'] == 0): ?>
                                            Custom
                                        <?php else: ?>
                                            <?php echo $template['duration_months']; ?>mo
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="card-body d-flex flex-column">
                                <p class="plan-description mb-3 text-muted">
                                    <?php echo htmlspecialchars($template['description'] ?: 'No description provided'); ?>
                                </p>
                                
                                <?php if ($template['suggested_monthly_amount']): ?>
                                <div class="border-start border-4 border-success ps-3 mb-3">
                                    <small class="text-muted d-block mb-1">Suggested Monthly Amount</small>
                                    <h4 class="mb-0 text-success">£<?php echo number_format($template['suggested_monthly_amount'], 2); ?></h4>
                                </div>
                                <?php endif; ?>
                                
                                <div class="plan-actions mt-auto">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" 
                                                   id="active_<?php echo $template['id']; ?>"
                                                   <?php echo $template['is_active'] ? 'checked' : ''; ?>
                                                   onchange="toggleActive(<?php echo $template['id']; ?>, this.checked)">
                                            <label class="form-check-label fw-semibold" for="active_<?php echo $template['id']; ?>">
                                                <?php echo $template['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </label>
                                        </div>
                                        
                                        <div class="d-flex align-items-center gap-2">
                                            <?php if ($template['is_default']): ?>
                                            <span class="badge bg-warning text-dark" style="font-size: 0.7rem;">
                                                <i class="fas fa-star me-1"></i>DEFAULT
                                            </span>
                                            <?php endif; ?>
                                            <div class="btn-group btn-group-sm">
                                                <button type="button" class="btn btn-outline-primary edit-template" 
                                                        data-template='<?php echo htmlspecialchars(json_encode($template), ENT_QUOTES); ?>'
                                                        title="Edit Template">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-danger delete-template" 
                                                        data-id="<?php echo $template['id']; ?>"
                                                        data-name="<?php echo htmlspecialchars($template['name']); ?>"
                                                        title="Delete Template">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php if (empty($templates)): ?>
                <div class="card border-0 shadow-sm animate-fade-in">
                    <div class="card-header bg-white border-bottom">
                        <h5 class="mb-0">
                            <i class="fas fa-th-list text-primary me-2"></i>Payment Plan Templates
                        </h5>
                    </div>
                    <div class="card-body text-center py-5">
                        <div class="mb-4">
                            <div class="d-inline-flex align-items-center justify-content-center bg-primary bg-opacity-10 rounded-circle" 
                                 style="width: 100px; height: 100px;">
                                <i class="fas fa-th-list fa-3x text-primary"></i>
                            </div>
                        </div>
                        <h4 class="mb-3">No Payment Plan Templates Yet</h4>
                        <p class="text-muted mb-4">Create your first template to offer flexible payment options to donors</p>
                        <button type="button" class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#createTemplateModal">
                            <i class="fas fa-plus-circle me-2"></i>Create Your First Template
                        </button>
                    </div>
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
                
                <div class="modal-header bg-white border-bottom">
                    <h5 class="modal-title">
                        <i class="fas fa-plus-circle text-primary me-2"></i>Create Payment Plan Template
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
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

<!-- Preview Plan Modal -->
<div class="modal fade" id="previewPlanModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-white border-bottom">
                <h5 class="modal-title">
                    <i class="fas fa-calculator text-primary me-2"></i>Preview Payment Plan
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            
            <div class="modal-body p-4">
                <div class="row g-4">
                    <!-- Left Column: Input -->
                    <div class="col-lg-5">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-light">
                            <h6 class="mb-0"><i class="fas fa-cog text-primary me-2"></i>Plan Configuration</h6>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Select Donor <span class="text-danger">*</span></label>
                                <select class="form-select" id="preview_donor" required>
                                    <option value="">Choose a donor...</option>
                                    <?php foreach ($donors_for_preview as $donor): ?>
                                    <option value="<?php echo $donor['id']; ?>" 
                                            data-balance="<?php echo $donor['balance']; ?>"
                                            data-name="<?php echo htmlspecialchars($donor['name']); ?>"
                                            data-phone="<?php echo htmlspecialchars($donor['phone']); ?>">
                                        <?php echo htmlspecialchars($donor['name']); ?> - 
                                        Balance: £<?php echo number_format($donor['balance'], 2); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Payment Plan Template <span class="text-danger">*</span></label>
                                <select class="form-select" id="preview_template" required>
                                    <option value="">Choose a plan...</option>
                                    <?php foreach ($templates as $template): ?>
                                    <option value="<?php echo $template['id']; ?>" 
                                            data-duration="<?php echo $template['duration_months']; ?>"
                                            data-name="<?php echo htmlspecialchars($template['name']); ?>">
                                        <?php echo htmlspecialchars($template['name']); ?>
                                        <?php if ($template['duration_months'] > 0): ?>
                                            (<?php echo $template['duration_months']; ?> months)
                                        <?php endif; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Start Date <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" id="preview_start_date" 
                                           value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Payment Day (1-28)</label>
                                    <input type="number" class="form-control" id="preview_payment_day" 
                                           min="1" max="28" value="1" required>
                                    <small class="text-muted">Day of month</small>
                                </div>
                            </div>
                            
                            <div class="mt-4 pt-3 border-top">
                                <button type="button" class="btn btn-primary w-100" id="calculatePlanBtn">
                                    <i class="fas fa-calculator me-2"></i>Calculate Plan
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Right Column: Results -->
                <div class="col-lg-7">
                    <div id="planPreviewResults" style="display: none;">
                        <div class="card border-0 shadow-sm mb-3">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="fas fa-chart-line text-success me-2"></i>Plan Summary</h6>
                            </div>
                            <div class="card-body">
                                <div class="row g-3 mb-3">
                                    <div class="col-6">
                                        <div class="border-start border-4 border-primary ps-3">
                                            <small class="text-muted d-block">Total Amount</small>
                                            <h4 class="mb-0 text-primary" id="preview_total_amount">-</h4>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="border-start border-4 border-success ps-3">
                                            <small class="text-muted d-block">Monthly Payment</small>
                                            <h4 class="mb-0 text-success" id="preview_monthly_amount">-</h4>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="border-start border-4 border-info ps-3">
                                            <small class="text-muted d-block">Duration</small>
                                            <h5 class="mb-0 text-info" id="preview_duration">-</h5>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="border-start border-4 border-warning ps-3">
                                            <small class="text-muted d-block">Donor Balance</small>
                                            <h5 class="mb-0 text-warning" id="preview_donor_balance">-</h5>
                                        </div>
                                    </div>
                                </div>
                                
                                <div id="preview_plan_match" class="alert" style="display: none;">
                                    <i class="fas fa-check-circle me-2"></i>
                                    <span id="preview_match_message"></span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="fas fa-calendar-alt text-primary me-2"></i>Payment Schedule</h6>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                                    <table class="table table-hover table-sm align-middle mb-0">
                                        <thead class="table-light sticky-top">
                                            <tr>
                                                <th>#</th>
                                                <th>Payment Date</th>
                                                <th class="text-end">Amount</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody id="preview_schedule_body">
                                            <!-- Schedule rows will be generated here -->
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div id="planPreviewEmpty" class="text-center py-5">
                        <div class="mb-4">
                            <div class="d-inline-flex align-items-center justify-content-center bg-light rounded-circle" 
                                 style="width: 80px; height: 80px;">
                                <i class="fas fa-calculator fa-2x text-muted"></i>
                            </div>
                        </div>
                        <h6 class="text-muted mb-2">Plan Preview</h6>
                        <p class="text-muted small mb-0">Select a donor and plan template, then click "Calculate Plan" to see the payment schedule</p>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-2"></i>Close
                </button>
            </div>
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
                
                <div class="modal-header bg-white border-bottom">
                    <h5 class="modal-title">
                        <i class="fas fa-edit text-primary me-2"></i>Edit Payment Plan Template
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
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
// Get CSRF token helper
function getCSRFToken() {
    const csrfInput = document.querySelector('input[name="csrf_token"]');
    if (csrfInput) {
        return csrfInput.value;
    }
    // Try to find it in any form
    const form = document.querySelector('form');
    if (form) {
        const token = form.querySelector('input[name="csrf_token"]');
        if (token) return token.value;
    }
    return '';
}

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
        $.ajax({
            url: '',
            method: 'POST',
            data: {
                action: 'reorder',
                order: JSON.stringify(order),
                csrf_token: getCSRFToken()
            },
            success: function(response) {
                // Silent success - order is saved
            },
            error: function() {
                alert('Failed to save order. Please refresh the page.');
            }
        });
    }
});

// Toggle active status
function toggleActive(templateId, isActive) {
    if (!templateId || templateId <= 0) {
        alert('Invalid template ID');
        return;
    }
    
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
$(document).on('click', '.edit-template', function(e) {
    e.stopPropagation();
    const templateData = $(this).attr('data-template');
    if (!templateData) {
        alert('Template data not found');
        return;
    }
    
    try {
        const template = JSON.parse(templateData);
        
        if (!template || !template.id) {
            alert('Invalid template data');
            return;
        }
        
        $('#edit_template_id').val(template.id);
        $('#edit_name').val(template.name || '');
        $('#edit_description').val(template.description || '');
        $('#edit_duration_months').val(template.duration_months || 0);
        $('#edit_suggested_monthly_amount').val(template.suggested_monthly_amount || '');
        $('#edit_is_default').prop('checked', template.is_default == 1);
        
        $('#editTemplateModal').modal('show');
    } catch (error) {
        console.error('Error parsing template data:', error);
        alert('Error loading template data');
    }
});

// Delete template
$(document).on('click', '.delete-template', function(e) {
    e.stopPropagation();
    const id = $(this).data('id');
    const name = $(this).data('name');
    
    if (!id || id <= 0) {
        alert('Invalid template ID');
        return;
    }
    
    if (confirm(`Are you sure you want to delete "${name || 'this template'}"? This action cannot be undone.`)) {
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

// Prevent row click from triggering when clicking buttons
$(document).on('click', '.plan-card .btn, .plan-card .form-check-input, .plan-card .form-check-label', function(e) {
    e.stopPropagation();
});

// Plan Preview Calculation
function calculatePaymentSchedule() {
    const donorSelect = document.getElementById('preview_donor');
    const templateSelect = document.getElementById('preview_template');
    const startDateInput = document.getElementById('preview_start_date');
    const paymentDayInput = document.getElementById('preview_payment_day');
    
    // Validation
    if (!donorSelect.value || !templateSelect.value || !startDateInput.value || !paymentDayInput.value) {
        alert('Please fill in all required fields');
        return;
    }
    
    const selectedDonor = donorSelect.options[donorSelect.selectedIndex];
    const selectedTemplate = templateSelect.options[templateSelect.selectedIndex];
    
    const balance = parseFloat(selectedDonor.getAttribute('data-balance'));
    const donorName = selectedDonor.getAttribute('data-name');
    const duration = parseInt(selectedTemplate.getAttribute('data-duration'));
    const templateName = selectedTemplate.getAttribute('data-name');
    const startDate = new Date(startDateInput.value);
    const paymentDay = parseInt(paymentDayInput.value);
    
    if (isNaN(balance) || balance <= 0) {
        alert('Invalid donor balance');
        return;
    }
    
    if (duration <= 0) {
        alert('Please select a valid payment plan template with duration');
        return;
    }
    
    if (isNaN(paymentDay) || paymentDay < 1 || paymentDay > 28) {
        alert('Payment day must be between 1 and 28');
        return;
    }
    
    // Calculate monthly payment (divide evenly, last payment may be adjusted)
    const monthlyAmount = balance / duration;
    const roundedMonthly = Math.floor(monthlyAmount * 100) / 100; // Round down to 2 decimals
    const remainder = balance - (roundedMonthly * duration);
    
    // Generate payment schedule
    const schedule = [];
    let currentDate = new Date(startDate);
    
    // Set the first payment date to the payment day of the month
    if (currentDate.getDate() > paymentDay) {
        // If start date is after payment day, move to next month
        currentDate.setMonth(currentDate.getMonth() + 1);
    }
    currentDate.setDate(Math.min(paymentDay, new Date(currentDate.getFullYear(), currentDate.getMonth() + 1, 0).getDate()));
    
    for (let i = 0; i < duration; i++) {
        const paymentDate = new Date(currentDate);
        
        // Last payment gets the remainder added
        const amount = (i === duration - 1) 
            ? roundedMonthly + remainder 
            : roundedMonthly;
        
        schedule.push({
            installment: i + 1,
            date: new Date(paymentDate),
            amount: amount,
            status: 'pending'
        });
        
        // Move to next month
        currentDate.setMonth(currentDate.getMonth() + 1);
        const daysInMonth = new Date(currentDate.getFullYear(), currentDate.getMonth() + 1, 0).getDate();
        currentDate.setDate(Math.min(paymentDay, daysInMonth));
    }
    
    // Update UI
    document.getElementById('preview_total_amount').textContent = '£' + balance.toFixed(2);
    document.getElementById('preview_monthly_amount').textContent = '£' + roundedMonthly.toFixed(2);
    document.getElementById('preview_duration').textContent = duration + ' months';
    document.getElementById('preview_donor_balance').textContent = '£' + balance.toFixed(2);
    
    // Check if plan matches balance perfectly
    const totalCalculated = schedule.reduce((sum, p) => sum + p.amount, 0);
    const difference = Math.abs(totalCalculated - balance);
    
    if (difference < 0.01) {
        document.getElementById('preview_plan_match').className = 'alert alert-success';
        document.getElementById('preview_match_message').textContent = 
            '✓ Plan matches donor balance perfectly!';
        document.getElementById('preview_plan_match').style.display = 'block';
    } else {
        document.getElementById('preview_plan_match').className = 'alert alert-warning';
        document.getElementById('preview_match_message').textContent = 
            `Note: Total calculated (£${totalCalculated.toFixed(2)}) differs from balance (£${balance.toFixed(2)}) by £${difference.toFixed(2)}`;
        document.getElementById('preview_plan_match').style.display = 'block';
    }
    
    // Generate schedule table
    const tbody = document.getElementById('preview_schedule_body');
    tbody.innerHTML = '';
    
    schedule.forEach((payment, index) => {
        const row = document.createElement('tr');
        const dateStr = payment.date.toLocaleDateString('en-GB', { 
            weekday: 'short', 
            year: 'numeric', 
            month: 'short', 
            day: 'numeric' 
        });
        
        row.innerHTML = `
            <td><strong>${payment.installment}</strong></td>
            <td><i class="fas fa-calendar text-muted me-2"></i>${dateStr}</td>
            <td class="text-end"><strong class="text-success">£${payment.amount.toFixed(2)}</strong></td>
            <td><span class="badge bg-secondary">Pending</span></td>
        `;
        
        tbody.appendChild(row);
    });
    
    // Show results
    document.getElementById('planPreviewResults').style.display = 'block';
    document.getElementById('planPreviewEmpty').style.display = 'none';
}

// Calculate button click
document.getElementById('calculatePlanBtn').addEventListener('click', calculatePaymentSchedule);

// Allow Enter key to calculate
['preview_donor', 'preview_template', 'preview_start_date', 'preview_payment_day'].forEach(id => {
    const element = document.getElementById(id);
    if (element) {
        element.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                calculatePaymentSchedule();
            }
        });
    }
});

// Reset modal when opened
$('#previewPlanModal').on('show.bs.modal', function() {
    // Reset form
    document.getElementById('preview_donor').value = '';
    document.getElementById('preview_template').value = '';
    document.getElementById('preview_start_date').value = '<?php echo date('Y-m-d'); ?>';
    document.getElementById('preview_payment_day').value = '1';
    
    // Hide results
    document.getElementById('planPreviewResults').style.display = 'none';
    document.getElementById('planPreviewEmpty').style.display = 'block';
    
    // Hide match alert
    document.getElementById('preview_plan_match').style.display = 'none';
});
</script>
</body>
</html>
