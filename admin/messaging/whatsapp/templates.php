<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

try {
    require_once __DIR__ . '/../../../shared/auth.php';
    require_once __DIR__ . '/../../../shared/csrf.php';
    require_once __DIR__ . '/../../../config/db.php';
    require_login();
} catch (Throwable $e) {
    die('Error: ' . $e->getMessage());
}

$page_title = 'WhatsApp Templates';
$current_user = current_user();
$db = db();

if (($current_user['role'] ?? '') !== 'admin') {
    header('Location: inbox.php');
    exit;
}

$templates = [];
$error_message = null;
$success_message = $_SESSION['success_message'] ?? null;
unset($_SESSION['success_message']);
$tables_exist = false;

// Check if table exists
try {
    $check = $db->query("SHOW TABLES LIKE 'sms_templates'");
    $tables_exist = $check && $check->num_rows > 0;
} catch (Throwable $e) {
    $error_message = 'Error checking tables: ' . $e->getMessage();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tables_exist) {
    try {
        verify_csrf();
        
        $action = $_POST['action'] ?? '';
        
        if ($action === 'create' || $action === 'update') {
            $template_key = trim($_POST['template_key'] ?? '');
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $category = $_POST['category'] ?? 'general';
            $message_en = trim($_POST['message_en'] ?? '');
            $variables = trim($_POST['variables'] ?? '');
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            if (empty($template_key) || empty($name) || empty($message_en)) {
                throw new Exception('Template key, name, and message are required.');
            }
            
            $template_key = preg_replace('/[^a-z0-9_]/', '', strtolower($template_key));
            
            // Convert comma-separated variables to JSON format
            if (!empty($variables)) {
                // Check if already JSON
                $decoded = json_decode($variables);
                if ($decoded === null) {
                    // Convert comma-separated to JSON array
                    $varArray = array_map('trim', explode(',', $variables));
                    $varArray = array_filter($varArray); // Remove empty
                    $variables = json_encode(array_values($varArray));
                }
            } else {
                $variables = '["name"]'; // Default variable as JSON
            }
            
            if ($action === 'create') {
                $priority = 'normal';
                $message_am = $message_en; // Use English as placeholder for other languages
                $message_ti = $message_en;
                
                $stmt = $db->prepare("
                    INSERT INTO sms_templates 
                    (template_key, name, description, category, message_en, message_am, message_ti, 
                     variables, priority, is_active, platform, created_by, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'whatsapp', ?, NOW(), NOW())
                ");
                if (!$stmt) {
                    throw new Exception('Prepare failed: ' . $db->error);
                }
                $stmt->bind_param('sssssssssii', 
                    $template_key, $name, $description, $category, 
                    $message_en, $message_am, $message_ti,
                    $variables, $priority, $is_active, $current_user['id']
                );
                if (!$stmt->execute()) {
                    throw new Exception('Execute failed: ' . $stmt->error);
                }
                $success_message = 'WhatsApp template created successfully!';
            } else {
                $template_id = (int)$_POST['template_id'];
                $stmt = $db->prepare("
                    UPDATE sms_templates 
                    SET template_key = ?, name = ?, description = ?, category = ?, 
                        message_en = ?, message_am = ?, message_ti = ?,
                        variables = ?, is_active = ?, updated_at = NOW()
                    WHERE id = ? AND platform IN ('whatsapp', 'both')
                ");
                if (!$stmt) {
                    throw new Exception('Prepare failed: ' . $db->error);
                }
                $stmt->bind_param('ssssssssii', 
                    $template_key, $name, $description, $category, 
                    $message_en, $message_en, $message_en,
                    $variables, $is_active, $template_id
                );
                if (!$stmt->execute()) {
                    throw new Exception('Execute failed: ' . $stmt->error);
                }
                $success_message = 'Template updated successfully!';
            }
            
            $_SESSION['success_message'] = $success_message;
            header('Location: templates.php');
            exit;
            
        } elseif ($action === 'delete') {
            $template_id = (int)$_POST['template_id'];
            $stmt = $db->prepare("DELETE FROM sms_templates WHERE id = ? AND platform IN ('whatsapp', 'both')");
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

// Get template for editing
$edit_template = null;
if (isset($_GET['edit']) && $tables_exist) {
    $edit_id = (int)$_GET['edit'];
    $stmt = $db->prepare("SELECT * FROM sms_templates WHERE id = ? AND platform IN ('whatsapp', 'both')");
    $stmt->bind_param('i', $edit_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $edit_template = $result->fetch_assoc();
}

$show_form = isset($_GET['action']) && $_GET['action'] === 'new' || $edit_template;

// Get all WhatsApp templates
if ($tables_exist) {
    $result = $db->query("
        SELECT * FROM sms_templates 
        WHERE platform IN ('whatsapp', 'both')
        ORDER BY category, name
    ");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $templates[] = $row;
        }
    }
}

$categories = [
    'greeting' => 'Greeting',
    'follow_up' => 'Follow Up',
    'payment_reminder' => 'Payment Reminder',
    'payment_confirmation' => 'Payment Confirmation',
    'fully_paid' => 'Fully Paid',
    'callback' => 'Callback',
    'thank_you' => 'Thank You',
    'general' => 'General',
    'promotional' => 'Promotional'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($page_title); ?> - Admin</title>
    <link rel="icon" type="image/svg+xml" href="../../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../../assets/admin.css">
    <style>
        :root {
            --wa-green: #25D366;
            --wa-teal: #00a884;
        }
        
        .card-wa {
            border: none;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
            border-radius: 12px;
        }
        
        .btn-wa {
            background: var(--wa-teal);
            color: white;
            border: none;
        }
        
        .btn-wa:hover {
            background: #00917a;
            color: white;
        }
        
        .template-card {
            background: white;
            border-radius: 12px;
            padding: 1.25rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            transition: all 0.2s;
            height: 100%;
        }
        
        .template-card:hover {
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
            transform: translateY(-2px);
        }
        
        .template-category {
            display: inline-block;
            padding: 0.25rem 0.625rem;
            background: #e0f2fe;
            color: #0369a1;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
            margin-bottom: 0.75rem;
        }
        
        .template-name {
            font-weight: 600;
            font-size: 1rem;
            color: #111b21;
            margin-bottom: 0.5rem;
        }
        
        .template-preview {
            font-size: 0.875rem;
            color: #667781;
            line-height: 1.5;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
            margin-bottom: 1rem;
        }
        
        .template-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .template-actions .btn {
            flex: 1;
            padding: 0.5rem;
            font-size: 0.8125rem;
        }
        
        .variable-tag {
            display: inline-block;
            background: #fef3c7;
            color: #92400e;
            padding: 0.125rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-family: monospace;
            margin: 0.125rem;
        }
        
        .char-count {
            font-size: 0.75rem;
            color: #667781;
        }
        
        .char-count.warning {
            color: #f59e0b;
        }
        
        .char-count.danger {
            color: #dc2626;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem 1.5rem;
            color: #667781;
        }
        
        .empty-state i {
            font-size: 3rem;
            color: var(--wa-teal);
            margin-bottom: 1rem;
        }
        
        @media (max-width: 768px) {
            .template-card {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <?php include __DIR__ . '/../../includes/sidebar.php'; ?>
        
        <main class="admin-main">
            <?php include __DIR__ . '/../../includes/header.php'; ?>
            
            <div class="admin-content p-3 p-md-4">
                <!-- Header -->
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
                    <div>
                        <h1 class="h4 mb-1">
                            <i class="fab fa-whatsapp text-success me-2"></i>WhatsApp Templates
                        </h1>
                        <p class="text-muted mb-0 small">Create and manage message templates for WhatsApp conversations</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="inbox.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-1"></i>Back to Inbox
                        </a>
                        <?php if (!$show_form): ?>
                        <a href="?action=new" class="btn btn-wa">
                            <i class="fas fa-plus me-1"></i>New Template
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if ($error_message): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
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
                <div class="card card-wa">
                    <div class="card-header bg-white py-3">
                        <h5 class="mb-0">
                            <i class="fas fa-<?php echo $edit_template ? 'edit' : 'plus'; ?> me-2"></i>
                            <?php echo $edit_template ? 'Edit Template' : 'Create New Template'; ?>
                        </h5>
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
                                    <label class="form-label">Template Key <span class="text-danger">*</span></label>
                                    <input type="text" name="template_key" class="form-control" required
                                           pattern="[a-z0-9_]+" placeholder="e.g., greeting_new_donor"
                                           value="<?php echo htmlspecialchars($edit_template['template_key'] ?? ''); ?>">
                                    <small class="text-muted">Lowercase letters, numbers, and underscores only</small>
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">Template Name <span class="text-danger">*</span></label>
                                    <input type="text" name="name" class="form-control" required
                                           placeholder="e.g., New Donor Greeting"
                                           value="<?php echo htmlspecialchars($edit_template['name'] ?? ''); ?>">
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">Category</label>
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
                                    <label class="form-label">Variables (comma separated)</label>
                                    <?php 
                                    $editVars = '';
                                    if (!empty($edit_template['variables'])) {
                                        $decoded = json_decode($edit_template['variables'], true);
                                        if (is_array($decoded)) {
                                            $editVars = implode(', ', $decoded);
                                        } else {
                                            $editVars = $edit_template['variables'];
                                        }
                                    }
                                    ?>
                                    <input type="text" name="variables" class="form-control"
                                           placeholder="e.g., name, amount, date"
                                           value="<?php echo htmlspecialchars($editVars); ?>">
                                    <small class="text-muted">Use {variable_name} in your message</small>
                                </div>
                                
                                <div class="col-12">
                                    <label class="form-label">Description</label>
                                    <input type="text" name="description" class="form-control"
                                           placeholder="Brief description of when to use this template"
                                           value="<?php echo htmlspecialchars($edit_template['description'] ?? ''); ?>">
                                </div>
                                
                                <div class="col-12">
                                    <label class="form-label">Message <span class="text-danger">*</span></label>
                                    <textarea name="message_en" class="form-control" rows="4" required
                                              id="messageInput" placeholder="Type your WhatsApp message here..."
                                    ><?php echo htmlspecialchars($edit_template['message_en'] ?? ''); ?></textarea>
                                    <div class="d-flex justify-content-between mt-1">
                                        <small class="text-muted">Use variables like {name}, {amount}, {date}</small>
                                        <span class="char-count" id="charCount">0 characters</span>
                                    </div>
                                </div>
                                
                                <div class="col-12">
                                    <div class="form-check">
                                        <input type="checkbox" name="is_active" class="form-check-input" id="isActive"
                                               <?php echo ($edit_template['is_active'] ?? 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="isActive">Active</label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="d-flex gap-2 mt-4">
                                <button type="submit" class="btn btn-wa">
                                    <i class="fas fa-save me-1"></i>
                                    <?php echo $edit_template ? 'Update Template' : 'Create Template'; ?>
                                </button>
                                <a href="templates.php" class="btn btn-outline-secondary">Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>
                
                <?php else: ?>
                
                <!-- Templates List -->
                <?php if (empty($templates)): ?>
                <div class="card card-wa">
                    <div class="card-body empty-state">
                        <i class="fab fa-whatsapp"></i>
                        <h4>No WhatsApp Templates Yet</h4>
                        <p>Create templates for quick, consistent messaging in WhatsApp conversations.</p>
                        <a href="?action=new" class="btn btn-wa">
                            <i class="fas fa-plus me-1"></i>Create First Template
                        </a>
                    </div>
                </div>
                <?php else: ?>
                
                <div class="row g-3">
                    <?php foreach ($templates as $template): ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="template-card">
                            <span class="template-category">
                                <?php echo htmlspecialchars($categories[$template['category']] ?? $template['category']); ?>
                            </span>
                            
                            <div class="template-name">
                                <?php echo htmlspecialchars($template['name']); ?>
                                <?php if (!$template['is_active']): ?>
                                <span class="badge bg-secondary ms-1">Inactive</span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="template-preview">
                                <?php echo htmlspecialchars($template['message_en']); ?>
                            </div>
                            
                            <?php if (!empty($template['variables'])): ?>
                            <div class="mb-3">
                                <?php 
                                $vars = json_decode($template['variables'], true) ?? [];
                                foreach ($vars as $var): 
                                ?>
                                <span class="variable-tag">{<?php echo htmlspecialchars(trim($var)); ?>}</span>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            
                            <div class="template-actions">
                                <a href="?edit=<?php echo $template['id']; ?>" class="btn btn-outline-primary btn-sm">
                                    <i class="fas fa-edit me-1"></i>Edit
                                </a>
                                <form method="POST" class="d-inline flex-grow-1" 
                                      onsubmit="return confirm('Delete this template?');">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="template_id" value="<?php echo $template['id']; ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm w-100">
                                        <i class="fas fa-trash me-1"></i>Delete
                                    </button>
                                </form>
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
    
    <?php include __DIR__ . '/../../includes/fab.php'; ?>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/admin.js"></script>
    <script>
    // Character counter
    const messageInput = document.getElementById('messageInput');
    const charCount = document.getElementById('charCount');
    
    if (messageInput && charCount) {
        function updateCharCount() {
            const len = messageInput.value.length;
            charCount.textContent = len + ' characters';
            
            charCount.classList.remove('warning', 'danger');
            if (len > 1000) {
                charCount.classList.add('danger');
            } else if (len > 500) {
                charCount.classList.add('warning');
            }
        }
        
        messageInput.addEventListener('input', updateCharCount);
        updateCharCount();
    }
    </script>
</body>
</html>

