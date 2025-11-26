<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../shared/auth.php';
require_once __DIR__ . '/../../../shared/csrf.php';
require_once __DIR__ . '/../../../config/db.php';
require_login();
require_admin();

$page_title = 'SMS Templates';
$current_user = current_user();
$db = db();

$templates = [];
$error_message = null;
$success_message = $_SESSION['success_message'] ?? null;
unset($_SESSION['success_message']);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'create' || $action === 'update') {
            $template_key = trim($_POST['template_key'] ?? '');
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $category = $_POST['category'] ?? 'general';
            $message_en = trim($_POST['message_en'] ?? '');
            $message_am = trim($_POST['message_am'] ?? '');
            $message_ti = trim($_POST['message_ti'] ?? '');
            $variables = trim($_POST['variables'] ?? '');
            $priority = $_POST['priority'] ?? 'normal';
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            // Validation
            if (empty($template_key) || empty($name) || empty($message_en)) {
                throw new Exception('Template key, name, and English message are required.');
            }
            
            // Sanitize template key
            $template_key = preg_replace('/[^a-z0-9_]/', '', strtolower($template_key));
            
            if ($action === 'create') {
                $stmt = $db->prepare("
                    INSERT INTO sms_templates 
                    (template_key, name, description, category, message_en, message_am, message_ti, 
                     variables, priority, is_active, created_by, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->bind_param('sssssssssii', 
                    $template_key, $name, $description, $category, 
                    $message_en, $message_am, $message_ti,
                    $variables, $priority, $is_active, $current_user['id']
                );
                $stmt->execute();
                $success_message = 'Template created successfully!';
            } else {
                $template_id = (int)$_POST['template_id'];
                $stmt = $db->prepare("
                    UPDATE sms_templates 
                    SET template_key = ?, name = ?, description = ?, category = ?,
                        message_en = ?, message_am = ?, message_ti = ?,
                        variables = ?, priority = ?, is_active = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->bind_param('ssssssssiii', 
                    $template_key, $name, $description, $category, 
                    $message_en, $message_am, $message_ti,
                    $variables, $priority, $is_active, $template_id
                );
                $stmt->execute();
                $success_message = 'Template updated successfully!';
            }
            
            $_SESSION['success_message'] = $success_message;
            header('Location: templates.php');
            exit;
        }
        
        if ($action === 'delete') {
            $template_id = (int)$_POST['template_id'];
            $stmt = $db->prepare("DELETE FROM sms_templates WHERE id = ?");
            $stmt->bind_param('i', $template_id);
            $stmt->execute();
            $_SESSION['success_message'] = 'Template deleted successfully!';
            header('Location: templates.php');
            exit;
        }
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// Get templates
try {
    $result = $db->query("
        SELECT t.*, u.name as created_by_name
        FROM sms_templates t
        LEFT JOIN users u ON t.created_by = u.id
        ORDER BY t.category, t.name
    ");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $templates[] = $row;
        }
    }
} catch (Exception $e) {
    $error_message = $e->getMessage();
}

// Get template for editing
$edit_template = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmt = $db->prepare("SELECT * FROM sms_templates WHERE id = ?");
    $stmt->bind_param('i', $edit_id);
    $stmt->execute();
    $edit_template = $stmt->get_result()->fetch_assoc();
}

$show_form = isset($_GET['action']) && $_GET['action'] === 'new' || $edit_template;

$categories = [
    'payment_reminder' => 'Payment Reminder',
    'payment_confirmation' => 'Payment Confirmation',
    'payment_overdue' => 'Payment Overdue',
    'plan_created' => 'Plan Created',
    'callback' => 'Callback',
    'missed_call' => 'Missed Call',
    'welcome' => 'Welcome',
    'general' => 'General',
    'promotional' => 'Promotional',
    'urgent' => 'Urgent'
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
    <style>
        .template-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: 1rem;
            transition: all 0.2s;
        }
        .template-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        .template-key {
            font-family: monospace;
            background: #f1f5f9;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.8125rem;
        }
        .message-preview {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 0.75rem;
            font-size: 0.875rem;
            color: #475569;
            max-height: 80px;
            overflow: hidden;
        }
        .variable-tag {
            display: inline-block;
            background: #dbeafe;
            color: #1d4ed8;
            padding: 0.125rem 0.375rem;
            border-radius: 4px;
            font-size: 0.75rem;
            margin: 0.125rem;
        }
        @media (max-width: 767px) {
            .template-card {
                padding: 1rem;
            }
        }
    </style>
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
                                <li class="breadcrumb-item active">Templates</li>
                            </ol>
                        </nav>
                        <h1 class="h3 mb-0">
                            <i class="fas fa-file-alt text-primary me-2"></i>SMS Templates
                        </h1>
                    </div>
                    <div>
                        <?php if (!$show_form): ?>
                            <a href="?action=new" class="btn btn-primary">
                                <i class="fas fa-plus me-2"></i>New Template
                            </a>
                        <?php else: ?>
                            <a href="templates.php" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left me-2"></i>Back to List
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if ($error_message): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($success_message): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($show_form): ?>
                    <!-- Template Form -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><?php echo $edit_template ? 'Edit Template' : 'Create New Template'; ?></h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <?php echo csrf_input(); ?>
                                <input type="hidden" name="action" value="<?php echo $edit_template ? 'update' : 'create'; ?>">
                                <?php if ($edit_template): ?>
                                    <input type="hidden" name="template_id" value="<?php echo $edit_template['id']; ?>">
                                <?php endif; ?>
                                
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold">Template Key <span class="text-danger">*</span></label>
                                        <input type="text" name="template_key" class="form-control" required
                                               placeholder="e.g., payment_reminder_3day"
                                               pattern="[a-z0-9_]+"
                                               value="<?php echo htmlspecialchars($edit_template['template_key'] ?? ''); ?>">
                                        <div class="form-text">Lowercase letters, numbers, underscores only</div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold">Display Name <span class="text-danger">*</span></label>
                                        <input type="text" name="name" class="form-control" required
                                               placeholder="e.g., 3-Day Payment Reminder"
                                               value="<?php echo htmlspecialchars($edit_template['name'] ?? ''); ?>">
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold">Category</label>
                                        <select name="category" class="form-select">
                                            <?php foreach ($categories as $key => $label): ?>
                                                <option value="<?php echo $key; ?>" 
                                                    <?php echo ($edit_template['category'] ?? '') === $key ? 'selected' : ''; ?>>
                                                    <?php echo $label; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold">Priority</label>
                                        <select name="priority" class="form-select">
                                            <option value="low" <?php echo ($edit_template['priority'] ?? '') === 'low' ? 'selected' : ''; ?>>Low</option>
                                            <option value="normal" <?php echo ($edit_template['priority'] ?? 'normal') === 'normal' ? 'selected' : ''; ?>>Normal</option>
                                            <option value="high" <?php echo ($edit_template['priority'] ?? '') === 'high' ? 'selected' : ''; ?>>High</option>
                                            <option value="urgent" <?php echo ($edit_template['priority'] ?? '') === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                                        </select>
                                    </div>
                                    
                                    <div class="col-12">
                                        <label class="form-label fw-semibold">Description</label>
                                        <input type="text" name="description" class="form-control"
                                               placeholder="What is this template used for?"
                                               value="<?php echo htmlspecialchars($edit_template['description'] ?? ''); ?>">
                                    </div>
                                    
                                    <div class="col-12">
                                        <label class="form-label fw-semibold">English Message <span class="text-danger">*</span></label>
                                        <textarea name="message_en" class="form-control" rows="3" required
                                                  placeholder="Hi {name}, your payment of £{amount} is due on {due_date}..."><?php echo htmlspecialchars($edit_template['message_en'] ?? ''); ?></textarea>
                                        <div class="form-text">Use {variable} for dynamic content. Max 160 chars per segment.</div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold">Amharic Message (Optional)</label>
                                        <textarea name="message_am" class="form-control" rows="3"
                                                  placeholder="Amharic translation..."><?php echo htmlspecialchars($edit_template['message_am'] ?? ''); ?></textarea>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold">Tigrinya Message (Optional)</label>
                                        <textarea name="message_ti" class="form-control" rows="3"
                                                  placeholder="Tigrinya translation..."><?php echo htmlspecialchars($edit_template['message_ti'] ?? ''); ?></textarea>
                                    </div>
                                    
                                    <div class="col-12">
                                        <label class="form-label fw-semibold">Variables (JSON Array)</label>
                                        <input type="text" name="variables" class="form-control"
                                               placeholder='["name", "amount", "due_date"]'
                                               value="<?php echo htmlspecialchars($edit_template['variables'] ?? ''); ?>">
                                        <div class="form-text">List of variables this template uses</div>
                                    </div>
                                    
                                    <div class="col-12">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" name="is_active" id="is_active"
                                                   <?php echo ($edit_template['is_active'] ?? 1) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="is_active">Template is active</label>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="d-flex gap-2 mt-4">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-2"></i><?php echo $edit_template ? 'Update Template' : 'Create Template'; ?>
                                    </button>
                                    <a href="templates.php" class="btn btn-outline-secondary">Cancel</a>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                <?php else: ?>
                    <!-- Templates List -->
                    <?php if (empty($templates)): ?>
                        <div class="card">
                            <div class="card-body text-center py-5">
                                <i class="fas fa-file-alt fa-4x text-muted mb-3"></i>
                                <h4>No Templates Yet</h4>
                                <p class="text-muted mb-4">Create your first SMS template to get started.</p>
                                <a href="?action=new" class="btn btn-primary">
                                    <i class="fas fa-plus me-2"></i>Create Template
                                </a>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="row">
                            <?php 
                            $current_category = null;
                            foreach ($templates as $template): 
                                if ($template['category'] !== $current_category):
                                    $current_category = $template['category'];
                            ?>
                                <div class="col-12 mb-3 mt-2">
                                    <h5 class="text-muted">
                                        <i class="fas fa-folder me-2"></i>
                                        <?php echo $categories[$current_category] ?? ucfirst($current_category); ?>
                                    </h5>
                                </div>
                            <?php endif; ?>
                            
                            <div class="col-12 col-md-6 col-xl-4">
                                <div class="template-card">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div>
                                            <h6 class="mb-1"><?php echo htmlspecialchars($template['name']); ?></h6>
                                            <code class="template-key"><?php echo htmlspecialchars($template['template_key']); ?></code>
                                        </div>
                                        <div>
                                            <?php if ($template['is_active']): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Inactive</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="message-preview mb-2">
                                        <?php echo htmlspecialchars($template['message_en']); ?>
                                    </div>
                                    
                                    <?php if (!empty($template['variables'])): ?>
                                        <div class="mb-2">
                                            <?php 
                                            $vars = json_decode($template['variables'], true) ?? [];
                                            foreach ($vars as $var): 
                                            ?>
                                                <span class="variable-tag">{<?php echo htmlspecialchars($var); ?>}</span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="d-flex justify-content-between align-items-center mt-3">
                                        <small class="text-muted">
                                            Used <?php echo number_format($template['usage_count'] ?? 0); ?> times
                                        </small>
                                        <div class="btn-group btn-group-sm">
                                            <a href="?edit=<?php echo $template['id']; ?>" class="btn btn-outline-primary">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <button type="button" class="btn btn-outline-danger" 
                                                    onclick="confirmDelete(<?php echo $template['id']; ?>, '<?php echo htmlspecialchars($template['name']); ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                
            </div>
        </main>
    </div>
</div>

<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Delete Template</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete "<strong id="deleteTemplateName"></strong>"?</p>
                <p class="text-danger mb-0"><small>This action cannot be undone.</small></p>
            </div>
            <div class="modal-footer">
                <form method="POST" id="deleteForm">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="template_id" id="deleteTemplateId">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/admin.js"></script>
<script>
function confirmDelete(id, name) {
    document.getElementById('deleteTemplateId').value = id;
    document.getElementById('deleteTemplateName').textContent = name;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
</script>
</body>
</html>

