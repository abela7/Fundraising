<?php
declare(strict_types=1);

// Enable error display for debugging
error_reporting(E_ALL);
ini_set('display_errors', '1');

try {
    require_once __DIR__ . '/../../../shared/auth.php';
} catch (Throwable $e) {
    die('Error loading auth.php: ' . $e->getMessage());
}

try {
    require_once __DIR__ . '/../../../shared/csrf.php';
} catch (Throwable $e) {
    die('Error loading csrf.php: ' . $e->getMessage());
}

try {
    require_once __DIR__ . '/../../../config/db.php';
} catch (Throwable $e) {
    die('Error loading db.php: ' . $e->getMessage());
}

try {
    require_login();
    // Allow both admin and registrar to access templates
    // Admin can edit/delete, registrar can view/create
} catch (Throwable $e) {
    die('Auth error: ' . $e->getMessage());
}

// Check user role
$is_admin = false;
try {
    $current_user_check = current_user();
    $is_admin = ($current_user_check['role'] ?? '') === 'admin';
} catch (Throwable $e) {
    // If can't check, default to non-admin
}

$page_title = 'WhatsApp Templates';
$current_user = null;
$db = null;

try {
    $current_user = current_user();
} catch (Throwable $e) {
    die('Error getting current user: ' . $e->getMessage());
}

try {
    $db = db();
} catch (Throwable $e) {
    die('Database connection error: ' . $e->getMessage());
}

$templates = [];
$error_message = null;
$success_message = $_SESSION['success_message'] ?? null;
unset($_SESSION['success_message']);
$tables_exist = false;

// Check if SMS tables exist
try {
    if ($db) {
        $check = $db->query("SHOW TABLES LIKE 'sms_templates'");
        $tables_exist = $check && $check->num_rows > 0;
        
        // Check if platform column exists
        if ($tables_exist) {
            $colCheck = $db->query("SHOW COLUMNS FROM sms_templates LIKE 'platform'");
            if (!$colCheck || $colCheck->num_rows === 0) {
                $error_message = 'Please run the database migration: database/add_platform_to_templates.sql';
            }
        }
    }
} catch (Throwable $e) {
    $error_message = 'Error checking tables: ' . $e->getMessage();
    $tables_exist = false;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tables_exist && $db) {
    try {
        verify_csrf();
    } catch (Throwable $e) {
        $error_message = 'CSRF verification failed: ' . $e->getMessage();
    }
    
    if (!$error_message) {
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
                $platform = 'whatsapp'; // Always WhatsApp for this page
                
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
                         variables, priority, is_active, platform, created_by, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ");
                    if (!$stmt) {
                        throw new Exception('Database error: ' . $db->error);
                    }
                    $stmt->bind_param('sssssssssiss', 
                        $template_key, $name, $description, $category, 
                        $message_en, $message_am, $message_ti,
                        $variables, $priority, $is_active, $platform, $current_user['id']
                    );
                    if (!$stmt->execute()) {
                        throw new Exception('Failed to create template: ' . $stmt->error);
                    }
                    $stmt->close();
                    $success_message = 'WhatsApp template created successfully!';
                } else {
                    // Only admin can edit existing templates
                    if (!$is_admin) {
                        throw new Exception('Only administrators can edit templates.');
                    }
                    
                    $template_id = (int)($_POST['template_id'] ?? 0);
                    if ($template_id <= 0) {
                        throw new Exception('Invalid template ID');
                    }
                    $stmt = $db->prepare("
                        UPDATE sms_templates 
                        SET template_key = ?, name = ?, description = ?, category = ?,
                            message_en = ?, message_am = ?, message_ti = ?,
                            variables = ?, priority = ?, is_active = ?, platform = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    if (!$stmt) {
                        throw new Exception('Database error: ' . $db->error);
                    }
                    $stmt->bind_param('sssssssssisi', 
                        $template_key, $name, $description, $category, 
                        $message_en, $message_am, $message_ti,
                        $variables, $priority, $is_active, $platform, $template_id
                    );
                    if (!$stmt->execute()) {
                        throw new Exception('Failed to update template: ' . $stmt->error);
                    }
                    $stmt->close();
                    $success_message = 'WhatsApp template updated successfully!';
                }
                
                $_SESSION['success_message'] = $success_message;
                header('Location: templates.php');
                exit;
            }
            
            if ($action === 'delete') {
                // Only admin can delete
                if (!$is_admin) {
                    throw new Exception('Only administrators can delete templates.');
                }
                
                $template_id = (int)($_POST['template_id'] ?? 0);
                if ($template_id <= 0) {
                    throw new Exception('Invalid template ID');
                }
                
                $stmt = $db->prepare("DELETE FROM sms_templates WHERE id = ? AND platform = 'whatsapp'");
                if (!$stmt) {
                    throw new Exception('Database error: ' . $db->error);
                }
                $stmt->bind_param('i', $template_id);
                if (!$stmt->execute()) {
                    throw new Exception('Failed to delete template: ' . $stmt->error);
                }
                $stmt->close();
                
                $_SESSION['success_message'] = 'Template deleted successfully!';
                header('Location: templates.php');
                exit;
            }
        } catch (Exception $e) {
            $error_message = $e->getMessage();
        }
    }
}

// Get all WhatsApp templates
if ($tables_exist && $db) {
    try {
        $result = $db->query("
            SELECT * FROM sms_templates 
            WHERE platform = 'whatsapp' OR platform = 'both'
            ORDER BY category, name
        ");
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $templates[] = $row;
            }
        }
    } catch (Exception $e) {
        $error_message = 'Error loading templates: ' . $e->getMessage();
    }
}

// Get template for editing (only admin can edit)
$edit_template = null;
if (isset($_GET['edit']) && $tables_exist && $db && $is_admin) {
    try {
        $edit_id = (int)$_GET['edit'];
        if ($edit_id > 0) {
            $stmt = $db->prepare("SELECT * FROM sms_templates WHERE id = ? AND (platform = 'whatsapp' OR platform = 'both')");
            if ($stmt) {
                $stmt->bind_param('i', $edit_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $edit_template = $result ? $result->fetch_assoc() : null;
                $stmt->close();
            }
        }
    } catch (Exception $e) {
        $error_message = 'Error loading template: ' . $e->getMessage();
    }
} elseif (isset($_GET['edit']) && !$is_admin) {
    $error_message = 'Only administrators can edit templates. You can create a new template based on an existing one.';
}

$show_form = (isset($_GET['action']) && $_GET['action'] === 'new') || $edit_template;

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

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="h3 mb-0">
                    <i class="fab fa-whatsapp text-success me-2"></i>WhatsApp Templates
                </h1>
                <a href="inbox.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-1"></i>Back to Inbox
                </a>
            </div>

            <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if (!$tables_exist): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                SMS templates table does not exist. Please run the database migration first.
            </div>
            <?php else: ?>

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-0">WhatsApp Message Templates</h5>
                        <?php if (!$is_admin): ?>
                        <small class="text-muted">View and copy templates, or create your own</small>
                        <?php endif; ?>
                    </div>
                    <?php if (!$show_form): ?>
                    <a href="?action=new" class="btn btn-success">
                        <i class="fas fa-plus me-1"></i>New Template
                    </a>
                    <?php endif; ?>
                </div>

                <div class="card-body">
                    <?php if ($show_form): ?>
                    <!-- Template Form -->
                    <?php if (isset($_GET['copy_from'])): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        You're creating a new template based on an existing one. Modify the fields as needed and save.
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!$is_admin && $edit_template): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Only administrators can edit existing templates. You can create a new template instead.
                    </div>
                    <?php endif; ?>
                    
                    <form method="POST" action="">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="action" value="<?php echo $edit_template ? 'update' : 'create'; ?>">
                        <?php if ($edit_template): ?>
                        <input type="hidden" name="template_id" value="<?php echo $edit_template['id']; ?>">
                        <?php endif; ?>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Template Key <span class="text-danger">*</span></label>
                                <input type="text" name="template_key" class="form-control" 
                                       value="<?php echo htmlspecialchars($edit_template['template_key'] ?? $_GET['template_key'] ?? ''); ?>" 
                                       required pattern="[a-z0-9_]+" 
                                       placeholder="e.g., welcome_message">
                                <small class="text-muted">Lowercase letters, numbers, and underscores only</small>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Template Name <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control" 
                                       value="<?php echo htmlspecialchars($edit_template['name'] ?? $_GET['name'] ?? ''); ?>" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="2"><?php echo htmlspecialchars($edit_template['description'] ?? $_GET['description'] ?? ''); ?></textarea>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Category</label>
                                <select name="category" class="form-select">
                                    <?php 
                                    $currentCategory = $edit_template['category'] ?? $_GET['category'] ?? '';
                                    foreach ($categories as $key => $label): 
                                    ?>
                                    <option value="<?php echo $key; ?>" 
                                            <?php echo $currentCategory === $key ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Priority</label>
                                <select name="priority" class="form-select">
                                    <?php 
                                    $currentPriority = $edit_template['priority'] ?? $_GET['priority'] ?? 'normal';
                                    ?>
                                    <option value="low" <?php echo $currentPriority === 'low' ? 'selected' : ''; ?>>Low</option>
                                    <option value="normal" <?php echo $currentPriority === 'normal' ? 'selected' : ''; ?>>Normal</option>
                                    <option value="high" <?php echo $currentPriority === 'high' ? 'selected' : ''; ?>>High</option>
                                </select>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Message (English) <span class="text-danger">*</span></label>
                            <textarea name="message_en" class="form-control" rows="4" required 
                                      placeholder="Hi {name}, thank you for your support!"><?php echo htmlspecialchars($edit_template['message_en'] ?? $_GET['message_en'] ?? ''); ?></textarea>
                            <small class="text-muted">Use {variable_name} for dynamic content</small>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Message (Amharic)</label>
                                <textarea name="message_am" class="form-control" rows="4" 
                                          placeholder="Amharic translation"><?php echo htmlspecialchars($edit_template['message_am'] ?? $_GET['message_am'] ?? ''); ?></textarea>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Message (Tigrinya)</label>
                                <textarea name="message_ti" class="form-control" rows="4" 
                                          placeholder="Tigrinya translation"><?php echo htmlspecialchars($edit_template['message_ti'] ?? $_GET['message_ti'] ?? ''); ?></textarea>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Available Variables</label>
                            <input type="text" name="variables" class="form-control" 
                                   value="<?php echo htmlspecialchars($edit_template['variables'] ?? $_GET['variables'] ?? ''); ?>" 
                                   placeholder="name, amount, date, etc.">
                            <small class="text-muted">Comma-separated list of variables used in the template</small>
                        </div>

                        <div class="mb-3">
                            <div class="form-check">
                                <input type="checkbox" name="is_active" class="form-check-input" id="isActive" 
                                       <?php echo ($edit_template['is_active'] ?? 1) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="isActive">Active</label>
                            </div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-save me-1"></i><?php echo $edit_template ? 'Update' : 'Create'; ?> Template
                            </button>
                            <a href="templates.php" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                    <?php else: ?>
                    <!-- Templates List -->
                    <?php if (!$is_admin): ?>
                    <div class="alert alert-info mb-3">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Note:</strong> You can view all templates and copy them to create your own. Only administrators can edit or delete existing templates.
                    </div>
                    <?php endif; ?>
                    
                    <?php if (empty($templates)): ?>
                    <div class="text-center py-5">
                        <i class="fab fa-whatsapp fa-3x text-muted mb-3"></i>
                        <p class="text-muted">No WhatsApp templates yet. Create your first template!</p>
                        <a href="?action=new" class="btn btn-success">
                            <i class="fas fa-plus me-1"></i>Create Template
                        </a>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Key</th>
                                    <th>Category</th>
                                    <th>Preview</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($templates as $template): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($template['name']); ?></strong>
                                        <?php if ($template['description']): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($template['description']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><code><?php echo htmlspecialchars($template['template_key']); ?></code></td>
                                    <td>
                                        <span class="badge bg-secondary">
                                            <?php echo htmlspecialchars($categories[$template['category']] ?? $template['category']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <small class="text-muted">
                                            <?php 
                                            $preview = substr($template['message_en'], 0, 60);
                                            echo htmlspecialchars($preview . (strlen($template['message_en']) > 60 ? '...' : '')); 
                                            ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?php if ($template['is_active']): ?>
                                        <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                        <span class="badge bg-secondary">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <?php if ($is_admin): ?>
                                            <!-- Admin: Edit and Delete -->
                                            <a href="?edit=<?php echo $template['id']; ?>" class="btn btn-outline-primary" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form method="POST" action="" class="d-inline" onsubmit="return confirm('Delete this template?');">
                                                <?php echo csrf_input(); ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="template_id" value="<?php echo $template['id']; ?>">
                                                <button type="submit" class="btn btn-outline-danger" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                            <?php else: ?>
                                            <!-- Registrar: Copy to create new -->
                                            <button type="button" class="btn btn-outline-success" 
                                                    onclick="copyTemplate(<?php echo htmlspecialchars(json_encode([
                                                        'name' => $template['name'] ?? '',
                                                        'description' => $template['description'] ?? '',
                                                        'category' => $template['category'] ?? 'general',
                                                        'message_en' => $template['message_en'] ?? '',
                                                        'message_am' => $template['message_am'] ?? '',
                                                        'message_ti' => $template['message_ti'] ?? '',
                                                        'variables' => $template['variables'] ?? '',
                                                        'priority' => $template['priority'] ?? 'normal'
                                                    ], JSON_HEX_APOS | JSON_HEX_QUOT)); ?>)" 
                                                    title="Copy to create new template">
                                                <i class="fas fa-copy"></i> Copy
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Copy template to create new one (for registrars)
function copyTemplate(template) {
    // Build URL with template data as query params
    const params = new URLSearchParams();
    params.append('action', 'new');
    params.append('copy_from', '1');
    params.append('name', (template.name || '') + ' (Copy)');
    params.append('description', template.description || '');
    params.append('category', template.category || 'general');
    params.append('message_en', template.message_en || '');
    params.append('message_am', template.message_am || '');
    params.append('message_ti', template.message_ti || '');
    params.append('variables', template.variables || '');
    params.append('priority', template.priority || 'normal');
    
    window.location.href = 'templates.php?' + params.toString();
}

// Auto-fill form if copying
<?php if (isset($_GET['copy_from']) && isset($_GET['action']) && $_GET['action'] === 'new'): ?>
document.addEventListener('DOMContentLoaded', function() {
    // Form is already pre-filled via URL params, just scroll to it
    setTimeout(() => {
        document.querySelector('form')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 100);
});
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

