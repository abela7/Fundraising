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
    require_admin();
} catch (Throwable $e) {
    die('Auth error: ' . $e->getMessage());
}

$page_title = 'SMS Templates';
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
    }
} catch (Throwable $e) {
    $error_message = 'Error checking tables: ' . $e->getMessage();
    $tables_exist = false;
}

// Detect all columns in sms_templates
$table_columns = [];
if ($tables_exist && $db) {
    $col_res = $db->query("SHOW COLUMNS FROM sms_templates");
    while ($col = $col_res->fetch_assoc()) {
        $table_columns[] = $col['Field'];
    }
}

/**
 * Resolve delivery mode from template row with backward compatibility.
 * Modes:
 * - auto: WhatsApp first (Amharic), then SMS fallback (English)
 * - sms: SMS always (English)
 * - whatsapp: WhatsApp always (Amharic)
 */
function resolve_delivery_mode(array $row): string {
    $mode = strtolower(trim((string)($row['preferred_channel'] ?? '')));
    if (in_array($mode, ['auto', 'sms', 'whatsapp'], true)) {
        return $mode;
    }

    $platform = strtolower(trim((string)($row['platform'] ?? '')));
    if ($platform === 'sms' || $platform === 'whatsapp') {
        return $platform;
    }

    return 'auto';
}

/**
 * Keep legacy `platform` aligned for compatibility with other pages.
 */
function mode_to_platform(string $mode): string {
    if ($mode === 'sms') return 'sms';
    if ($mode === 'whatsapp') return 'whatsapp';
    return 'both';
}

/**
 * Core Amharic translations for known system templates.
 */
function core_amharic_template_map(): array {
    return [
        'missed_call' => "ሰላም {name}፣\n\nከሊቨርፑል አቡነ ተክለሃይማኖት ቤተክርስቲያን ደውለን ነበር ነገር ግን ሊደርስልዎ አልቻለም። በ{callback_date} በ{callback_time} እንደገና እንደውላለን። እናመሰግናለን!",
        'line_busy' => "ሰላም {name}፣\n\nከአቡነ ተክለሃይማኖት ቤተክርስቲያን ስንደውል መስመርዎ ተጠምዶ ነበር። በ{callback_date} በ{callback_time} እንደገና እንሞክራለን። እናመሰግናለን!",
        'callback_requested' => "ሰላም {name}፣\n\nእንደተስማማነው በ{callback_date} በ{callback_time} እንደገና እንደውላለን። እናመሰግናለን! - ሊቨርፑል አቡነ ተክለሃይማኖት ቤተክርስቲያን",
        'follow_up_reminder' => "ሰላም {name}፣\n\nስለ ወቅታዊ ጊዜዎ እናመሰግናለን። በ{callback_date} በ{callback_time} እንደገና እንከታተላለን። - ሊቨርፑል አቡነ ተክለሃይማኖት ቤተክርስቲያን",
        'payment_plan_created' => "ሰላም {name}፣ ከሊቨርፑል አቡነ ተክለሃይማኖት ቤተክርስቲያን ጋር የክፍያ እቅድ ስላዘጋጁ እናመሰግናለን! ከ{start_date} ጀምሮ {amount} {frequency_am} ይከፍላሉ። የክፍያ ዘዴ: {payment_method}። ቀጣዩ ክፍያዎ {next_payment_due} ነው። ከክፍያ ቀንዎ 2 ቀን በፊት እናሳስባለን። - እግዚአብሔር ይባርክዎ!",
        'payment_reminder_2day' => "ውድ {name}፣ በክፍያ እቅድዎ መሠረት ቀጣዩ የ{amount} ክፍያዎ በ{due_date} ይከፈላል። የክፍያ ዘዴ: {payment_method}። {payment_instructions}። እናመሰግናለን! - ሊቨርፑል አቡነ ተክለሃይማኖት ቤተክርስቲያን",
        'missed_payment_reminder' => "ሰላም {name}፣\n\nበ{missed_date} መከፈል የነበረበትን {amount} ክፍያ አልተከፈለም።\n\nእባክዎ የክፍያ እቅድዎን ለማስቀጠል በተቻለ ፍጥነት ክፍያዎን ይፈጽሙ።\n{payment_instructions}\n\nቀጣዩ የ{amount} ክፍያዎ በ{next_payment_date} ነው።\n\nጥያቄ ካለዎት እባክዎን ያግኙን።\n\nእግዚአብሔር ይባርክዎ! 🙏\n- ሊቨርፑል አቡነ ተክለሃይማኖት ቤተክርስቲያን",
        'payment_confirmed' => "ሰላም ጤና ይስጥልን ወድ {name}፣\n\nበዛሬው ዕለት ማለትም {payment_date} የ {amount} ፓውንድ ክፍያዎን ተቀብለናል።\n\nየቃል ኪዳንዎ ማጠቃለያ፡\n→ ጠቅላላ ቃል ኪዳን የገቡት፡ {total_pledge}\n→ እስካሁን የከፈሉት: {total_paid}\n→ ቀሪ ሂሳብ፡ {outstanding_balance}\n\n{next_payment_info}\n\nማንኛውም ጥያቄ ካለዎት እባክዎ ያነጋግሩን።\n\nአምላከ ተክለሃይማኖት በሰጡት አብዝቶ ይስጥልን🙏\n\n- ሊቨርፑል አቡነ ተክለሃይማኖት ቤተ ክርስቲያን",
        'fully_paid_confirmation' => "ሰላም ጤና ይስጥልን ወድ {donor_name}፣\n\nሙሉ ቃል ኪዳን ክፍያዎን ስለጨረሱ እናመሰግናለን።\n\nበዛሬው ዕለት ({date}) የተቀበልነው ክፍያ: £{payment_amount}\n\nየቃል ኪዳንዎ ማጠቃለያ፡\n→ ጠቅላላ ቃል ኪዳን: {total_pledged_sqm} ካሬ ሜትር, £{total_pledged}\n→ ጠቅላላ የከፈሉት: £{total_paid}\n→ ቀሪ: £{remaining}\n\nአምላከ ተክለሃይማኖት በሰጡት አብዝቶ ይስጥልን።\n\n- ሊቨርፑል አቡነ ተክለሃይማኖት ቤተ ክርስቲያን"
    ];
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
        $has_preferred_channel = in_array('preferred_channel', $table_columns, true);
        $has_platform = in_array('platform', $table_columns, true);
        
        try {
            if ($action === 'apply_amharic_pack') {
                $translations = core_amharic_template_map();

                $selectCols = ['id', 'template_key', 'message_en', 'message_am'];
                if ($has_preferred_channel) $selectCols[] = 'preferred_channel';
                if ($has_platform) $selectCols[] = 'platform';

                $query = "SELECT " . implode(', ', $selectCols) . " FROM sms_templates";
                $res = $db->query($query);
                if (!$res) {
                    throw new Exception('Failed to read templates for Amharic pack.');
                }

                $updated_rows = 0;
                $translated_count = 0;
                $copied_count = 0;
                $mode_fixed = 0;
                $platform_fixed = 0;

                while ($row = $res->fetch_assoc()) {
                    $updates = [];
                    $vals = [];
                    $types = '';

                    $templateKey = strtolower(trim((string)($row['template_key'] ?? '')));
                    $currentAm = trim((string)($row['message_am'] ?? ''));

                    // Fill Amharic text if missing.
                    if ($currentAm === '') {
                        if (isset($translations[$templateKey])) {
                            $newAm = $translations[$templateKey];
                            $translated_count++;
                        } else {
                            // Fallback to English text to avoid empty WhatsApp messages.
                            $newAm = trim((string)($row['message_en'] ?? ''));
                            $copied_count++;
                        }

                        if ($newAm !== '') {
                            $updates[] = 'message_am = ?';
                            $vals[] = $newAm;
                            $types .= 's';
                        }
                    }

                    // Normalize mode and keep legacy platform aligned.
                    $mode = resolve_delivery_mode($row);
                    if ($has_preferred_channel) {
                        $currentMode = strtolower(trim((string)($row['preferred_channel'] ?? '')));
                        if (!in_array($currentMode, ['auto', 'sms', 'whatsapp'], true)) {
                            $updates[] = 'preferred_channel = ?';
                            $vals[] = $mode;
                            $types .= 's';
                            $mode_fixed++;
                        }
                    }
                    if ($has_platform) {
                        $targetPlatform = mode_to_platform($mode);
                        $currentPlatform = strtolower(trim((string)($row['platform'] ?? '')));
                        if ($currentPlatform !== $targetPlatform) {
                            $updates[] = 'platform = ?';
                            $vals[] = $targetPlatform;
                            $types .= 's';
                            $platform_fixed++;
                        }
                    }

                    if (!empty($updates)) {
                        $vals[] = (int)$row['id'];
                        $types .= 'i';
                        $sql = "UPDATE sms_templates SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE id = ?";
                        $stmt = $db->prepare($sql);
                        if (!$stmt) {
                            throw new Exception('Failed to update template #' . (int)$row['id'] . ': ' . $db->error);
                        }
                        $stmt->bind_param($types, ...$vals);
                        if (!$stmt->execute()) {
                            throw new Exception('Failed to update template #' . (int)$row['id'] . ': ' . $stmt->error);
                        }
                        $stmt->close();
                        $updated_rows++;
                    }
                }

                $_SESSION['success_message'] = "Amharic pack applied. Updated {$updated_rows} templates ({$translated_count} translated, {$copied_count} copied from English, {$mode_fixed} mode fixes, {$platform_fixed} platform fixes).";
                header('Location: templates.php');
                exit;
            }

            if ($action === 'create' || $action === 'update') {
                $template_key = trim($_POST['template_key'] ?? '');
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $category = $_POST['category'] ?? 'general';
                $message_en = trim($_POST['message_en'] ?? '');
                $message_am = trim($_POST['message_am'] ?? '');
                $message_ti = trim($_POST['message_ti'] ?? '');
                $variables_raw = trim($_POST['variables'] ?? '');
                $priority = $_POST['priority'] ?? 'normal';
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                $delivery_mode = strtolower(trim((string)($_POST['preferred_channel'] ?? 'auto')));
                if (!in_array($delivery_mode, ['auto', 'sms', 'whatsapp'], true)) {
                    $delivery_mode = 'auto';
                }
                
                // Advanced rules (if columns exist)
                $max_sends = isset($_POST['max_sends_per_donor']) && $_POST['max_sends_per_donor'] !== '' ? (int)$_POST['max_sends_per_donor'] : null;
                $min_interval = isset($_POST['min_interval_hours']) && $_POST['min_interval_hours'] !== '' ? (int)$_POST['min_interval_hours'] : 24;
                $window_start = !empty($_POST['send_window_start']) ? $_POST['send_window_start'] : null;
                $window_end = !empty($_POST['send_window_end']) ? $_POST['send_window_end'] : null;
                $exclude_weekends = isset($_POST['exclude_weekends']) ? 1 : 0;
                $requires_approval = isset($_POST['requires_approval']) ? 1 : 0;
                
                // Process variables into JSON
                $variables_arr = [];
                if (!empty($variables_raw)) {
                    // Try parsing as JSON first
                    $decoded = json_decode($variables_raw, true);
                    if (is_array($decoded)) {
                        $variables_arr = $decoded;
                    } else {
                        // Fallback: split by comma and remove braces/spaces
                        $parts = explode(',', $variables_raw);
                        foreach ($parts as $part) {
                            $clean = trim($part, " \t\n\r\0\x0B{}[]'\"");
                            if (!empty($clean)) $variables_arr[] = $clean;
                        }
                    }
                }
                $variables = json_encode($variables_arr);
                
                // Validation
                if (empty($template_key) || empty($name) || empty($message_en)) {
                    throw new Exception('Template key, name, and English message are required.');
                }
                
                // Sanitize template key
                $template_key = preg_replace('/[^a-z0-9_]/', '', strtolower($template_key));

                // Auto-fill Amharic from core map if left empty.
                if ($message_am === '') {
                    $translations = core_amharic_template_map();
                    if (isset($translations[$template_key])) {
                        $message_am = $translations[$template_key];
                    }
                }
                
                $fields = [
                    'template_key' => $template_key,
                    'name' => $name,
                    'description' => $description,
                    'category' => $category,
                    'message_en' => $message_en,
                    'message_am' => $message_am,
                    'message_ti' => $message_ti,
                    'variables' => $variables,
                    'priority' => $priority,
                    'is_active' => $is_active
                ];
                
                // Add optional columns if they exist in schema
                if ($has_preferred_channel) $fields['preferred_channel'] = $delivery_mode;
                if ($has_platform) $fields['platform'] = mode_to_platform($delivery_mode);
                if (in_array('max_sends_per_donor', $table_columns)) $fields['max_sends_per_donor'] = $max_sends;
                if (in_array('min_interval_hours', $table_columns)) $fields['min_interval_hours'] = $min_interval;
                if (in_array('send_window_start', $table_columns)) $fields['send_window_start'] = $window_start;
                if (in_array('send_window_end', $table_columns)) $fields['send_window_end'] = $window_end;
                if (in_array('exclude_weekends', $table_columns)) $fields['exclude_weekends'] = $exclude_weekends;
                if (in_array('requires_approval', $table_columns)) $fields['requires_approval'] = $requires_approval;

                if ($action === 'create') {
                    $fields['created_by'] = $current_user['id'];
                    $col_names = implode(', ', array_keys($fields)) . ", created_at, updated_at";
                    $placeholders = implode(', ', array_fill(0, count($fields), '?')) . ", NOW(), NOW()";
                    
                    $stmt = $db->prepare("INSERT INTO sms_templates ($col_names) VALUES ($placeholders)");
                    if (!$stmt) throw new Exception('Database error: ' . $db->error);
                    
                    $types = '';
                    $vals = [];
                    foreach ($fields as $val) {
                        if (is_int($val)) $types .= 'i';
                        elseif (is_double($val)) $types .= 'd';
                        else $types .= 's';
                        $vals[] = $val;
                    }
                    
                    $stmt->bind_param($types, ...$vals);
                } else {
                    $template_id = (int)($_POST['template_id'] ?? 0);
                    if ($template_id <= 0) throw new Exception('Invalid template ID');
                    
                    $sets = [];
                    $vals = [];
                    $types = '';
                    foreach ($fields as $key => $val) {
                        $sets[] = "$key = ?";
                        if (is_int($val)) $types .= 'i';
                        elseif (is_double($val)) $types .= 'd';
                        else $types .= 's';
                        $vals[] = $val;
                    }
                    $vals[] = $template_id;
                    $types .= 'i';
                    
                    $set_sql = implode(', ', $sets) . ", updated_at = NOW()";
                    $stmt = $db->prepare("UPDATE sms_templates SET $set_sql WHERE id = ?");
                    if (!$stmt) throw new Exception('Database error: ' . $db->error);
                    
                    $stmt->bind_param($types, ...$vals);
                }
                
                if (!$stmt->execute()) {
                    throw new Exception('Failed to save template: ' . $stmt->error);
                }
                $stmt->close();
                $success_message = 'Template saved successfully!';
                
                $_SESSION['success_message'] = $success_message;
                header('Location: templates.php');
                exit;
            }
            
            if ($action === 'delete') {
                $template_id = (int)($_POST['template_id'] ?? 0);
                if ($template_id <= 0) {
                    throw new Exception('Invalid template ID');
                }
                $stmt = $db->prepare("DELETE FROM sms_templates WHERE id = ?");
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

// Get templates
if ($tables_exist && $db) {
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
        $error_message = 'Error loading templates: ' . $e->getMessage();
    }
}

// Get template for editing
$edit_template = null;
if (isset($_GET['edit']) && $tables_exist && $db) {
    try {
        $edit_id = (int)$_GET['edit'];
        if ($edit_id > 0) {
            $stmt = $db->prepare("SELECT * FROM sms_templates WHERE id = ?");
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
}

$show_form = isset($_GET['action']) && $_GET['action'] === 'new' || $edit_template;

$categories = [
    'payment_reminder' => 'Payment Reminder',
    'payment_confirmation' => 'Payment Confirmation',
    'payment_overdue' => 'Payment Overdue',
    'fully_paid' => 'Fully Paid',
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
            display: flex;
            flex-direction: column;
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
            display: inline-block;
            margin-top: 0.25rem;
        }
        .message-preview {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 0.75rem;
            font-size: 0.875rem;
            color: #475569;
            overflow-y: auto;
            white-space: pre-wrap;
            margin-top: 0.5rem;
        }
        .variable-tag {
            display: inline-block;
            background: #dbeafe;
            color: #1d4ed8;
            padding: 0.125rem 0.375rem;
            border-radius: 4px;
            font-size: 0.75rem;
            margin: 0.125rem;
            cursor: help;
        }
        .form-label {
            margin-bottom: 0.25rem;
        }
        textarea.form-control {
            font-family: inherit;
            line-height: 1.5;
        }
        hr {
            opacity: 0.1;
        }
        .badge {
            font-weight: 500;
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
    <?php 
    try {
        include '../../includes/sidebar.php'; 
    } catch (Throwable $e) {
        error_log('Error loading sidebar: ' . $e->getMessage());
    }
    ?>
    
    <div class="admin-content">
        <?php 
        try {
            include '../../includes/topbar.php'; 
        } catch (Throwable $e) {
            error_log('Error loading topbar: ' . $e->getMessage());
        }
        ?>
        
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
                    <div class="d-flex gap-2 flex-wrap">
                        <?php if (!$show_form): ?>
                            <form method="POST" class="d-inline">
                                <?php echo csrf_input(); ?>
                                <input type="hidden" name="action" value="apply_amharic_pack">
                                <button type="submit" class="btn btn-outline-success" onclick="return confirm('Apply Amharic pack to existing templates? This will fill missing Amharic messages and normalize delivery mode settings.');">
                                    <i class="fas fa-language me-2"></i>Apply Amharic Pack
                                </button>
                            </form>
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
                
                <?php if (!$tables_exist): ?>
                    <div class="alert alert-warning alert-dismissible fade show">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>SMS Templates table not found!</strong> Please run the database setup script to create the required tables.
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
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
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold">Template Key <span class="text-danger">*</span></label>
                                        <input type="text" name="template_key" class="form-control" required
                                               placeholder="e.g., payment_reminder_3day"
                                               pattern="[a-z0-9_]+"
                                               value="<?php echo htmlspecialchars($edit_template['template_key'] ?? ''); ?>">
                                        <div class="form-text">Lowercase letters, numbers, underscores only</div>
                                    </div>
                                    
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold">Display Name <span class="text-danger">*</span></label>
                                        <input type="text" name="name" class="form-control" required
                                               placeholder="e.g., 3-Day Payment Reminder"
                                               value="<?php echo htmlspecialchars($edit_template['name'] ?? ''); ?>">
                                    </div>

                                    <div class="col-md-4">
                                        <?php $form_delivery_mode = resolve_delivery_mode($edit_template ?? []); ?>
                                        <label class="form-label fw-semibold">Delivery Mode</label>
                                        <select name="preferred_channel" class="form-select">
                                            <option value="auto" <?php echo $form_delivery_mode === 'auto' ? 'selected' : ''; ?>>Default (WhatsApp first, then SMS fallback)</option>
                                            <option value="sms" <?php echo $form_delivery_mode === 'sms' ? 'selected' : ''; ?>>SMS Always (English)</option>
                                            <option value="whatsapp" <?php echo $form_delivery_mode === 'whatsapp' ? 'selected' : ''; ?>>WhatsApp Always (Amharic)</option>
                                        </select>
                                        <div class="form-text">Template-level sending policy. Default uses WhatsApp first, then SMS fallback.</div>
                                    </div>
                                    
                                    <div class="col-md-4">
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
                                    
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold">Priority</label>
                                        <select name="priority" class="form-select">
                                            <option value="low" <?php echo ($edit_template['priority'] ?? '') === 'low' ? 'selected' : ''; ?>>Low</option>
                                            <option value="normal" <?php echo ($edit_template['priority'] ?? 'normal') === 'normal' ? 'selected' : ''; ?>>Normal</option>
                                            <option value="high" <?php echo ($edit_template['priority'] ?? '') === 'high' ? 'selected' : ''; ?>>High</option>
                                            <option value="urgent" <?php echo ($edit_template['priority'] ?? '') === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                                        </select>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold">Variables (Comma-separated)</label>
                                        <?php 
                                        $var_display = '';
                                        if (!empty($edit_template['variables'])) {
                                            $decoded = json_decode($edit_template['variables'], true);
                                            if (is_array($decoded)) {
                                                $var_display = implode(', ', $decoded);
                                            } else {
                                                $var_display = $edit_template['variables'];
                                            }
                                        }
                                        ?>
                                        <input type="text" name="variables" class="form-control"
                                               placeholder="name, amount, due_date"
                                               value="<?php echo htmlspecialchars($var_display); ?>">
                                        <div class="form-text">Variables to replace in message</div>
                                    </div>
                                    
                                    <div class="col-12">
                                        <label class="form-label fw-semibold">Description</label>
                                        <input type="text" name="description" class="form-control"
                                               placeholder="What is this template used for?"
                                               value="<?php echo htmlspecialchars($edit_template['description'] ?? ''); ?>">
                                    </div>
                                    
                                    <div class="col-12">
                                        <label class="form-label fw-semibold">English Message <span class="text-danger">*</span></label>
                                        <textarea name="message_en" id="message_en" class="form-control" rows="4" required
                                                  placeholder="Hi {name}, your payment of £{amount} is due on {due_date}..."
                                                  oninput="updateCharCount()"><?php echo htmlspecialchars($edit_template['message_en'] ?? ''); ?></textarea>
                                        <div class="form-text d-flex justify-content-between align-items-center">
                                            <span>Use {variable} for dynamic content. Max 160 chars per segment.</span>
                                            <span id="char-count" class="badge <?php 
                                                $msgLen = strlen($edit_template['message_en'] ?? ''); 
                                                echo $msgLen > 160 ? 'bg-danger' : ($msgLen > 140 ? 'bg-warning' : 'bg-success'); 
                                            ?>">
                                                <span id="char-count-num"><?php echo $msgLen; ?></span> / 160
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold">Amharic Message (Optional)</label>
                                        <textarea name="message_am" class="form-control" rows="6"
                                                  placeholder="Amharic translation..."><?php echo htmlspecialchars($edit_template['message_am'] ?? ''); ?></textarea>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold">Tigrinya Message (Optional)</label>
                                        <textarea name="message_ti" class="form-control" rows="6"
                                                  placeholder="Tigrinya translation..."><?php echo htmlspecialchars($edit_template['message_ti'] ?? ''); ?></textarea>
                                    </div>

                                    <hr class="my-4">
                                    <h6 class="mb-2">Advanced Rules & Constraints</h6>
                                    
                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold">Max Sends / Donor</label>
                                        <input type="number" name="max_sends_per_donor" class="form-control"
                                               placeholder="Unlimited"
                                               value="<?php echo htmlspecialchars((string)($edit_template['max_sends_per_donor'] ?? '')); ?>">
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold">Min Interval (Hours)</label>
                                        <input type="number" name="min_interval_hours" class="form-control"
                                               value="<?php echo htmlspecialchars((string)($edit_template['min_interval_hours'] ?? 24)); ?>">
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold">Send Window Start</label>
                                        <input type="time" name="send_window_start" class="form-control"
                                               value="<?php echo htmlspecialchars($edit_template['send_window_start'] ?? ''); ?>">
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold">Send Window End</label>
                                        <input type="time" name="send_window_end" class="form-control"
                                               value="<?php echo htmlspecialchars($edit_template['send_window_end'] ?? ''); ?>">
                                    </div>
                                    
                                    <div class="col-md-4 mt-4">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" name="is_active" id="is_active"
                                                   <?php echo ($edit_template['is_active'] ?? 1) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="is_active">Template is active</label>
                                        </div>
                                    </div>

                                    <div class="col-md-4 mt-4">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" name="exclude_weekends" id="exclude_weekends"
                                                   <?php echo ($edit_template['exclude_weekends'] ?? 0) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="exclude_weekends">Exclude Weekends</label>
                                        </div>
                                    </div>

                                    <div class="col-md-4 mt-4">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" name="requires_approval" id="requires_approval"
                                                   <?php echo ($edit_template['requires_approval'] ?? 0) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="requires_approval">Requires Approval</label>
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
                                <div class="template-card h-100">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div>
                                            <h6 class="mb-1 text-truncate" style="max-width: 200px;"><?php echo htmlspecialchars($template['name']); ?></h6>
                                            <code class="template-key"><?php echo htmlspecialchars($template['template_key']); ?></code>
                                        </div>
                                        <div class="d-flex flex-column align-items-end gap-1">
                                            <?php if ($template['is_active']): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Inactive</span>
                                            <?php endif; ?>
                                            
                                            <?php
                                                $templateMode = resolve_delivery_mode($template);
                                                $modeIcon = $templateMode === 'whatsapp'
                                                    ? 'fab fa-whatsapp'
                                                    : ($templateMode === 'sms' ? 'fas fa-sms' : 'fas fa-random');
                                                $modeLabel = $templateMode === 'whatsapp'
                                                    ? 'WHATSAPP ALWAYS'
                                                    : ($templateMode === 'sms' ? 'SMS ALWAYS' : 'DEFAULT');
                                            ?>
                                            <span class="badge bg-info text-dark" style="font-size: 0.65rem;">
                                                <i class="<?php echo $modeIcon; ?> me-1"></i>
                                                <?php echo $modeLabel; ?>
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <div class="message-preview mb-2" style="max-height: 100px;">
                                        <?php echo htmlspecialchars($template['message_en']); ?>
                                    </div>
                                    
                                    <?php if (!empty($template['variables'])): ?>
                                        <div class="mb-2">
                                            <?php 
                                            $vars = [];
                                            $decoded = json_decode($template['variables'], true);
                                            if (is_array($decoded)) {
                                                $vars = $decoded;
                                            } else {
                                                // Fallback if not JSON
                                                $vars = array_map('trim', explode(',', $template['variables']));
                                            }
                                            foreach ($vars as $var): 
                                                $var = trim($var, "{}[] ");
                                                if (empty($var)) continue;
                                            ?>
                                                <span class="variable-tag">{<?php echo htmlspecialchars($var); ?>}</span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="mt-auto pt-3 border-top d-flex justify-content-between align-items-center">
                                        <div class="small text-muted">
                                            <i class="fas fa-paper-plane me-1"></i>
                                            <?php echo number_format((int)($template['usage_count'] ?? 0)); ?> sends
                                        </div>
                                        <div class="btn-group btn-group-sm">
                                            <a href="?edit=<?php echo (int)$template['id']; ?>" class="btn btn-outline-primary" title="Edit Template">
                                                <i class="fas fa-edit"></i>
                                                <span class="ms-1">Edit</span>
                                            </a>
                                            <button type="button" class="btn btn-outline-danger" 
                                                    onclick="confirmDelete(<?php echo (int)$template['id']; ?>, '<?php echo htmlspecialchars(addslashes($template['name'] ?? 'Unknown')); ?>')"
                                                    title="Delete Template">
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

function updateCharCount() {
    const textarea = document.getElementById('message_en');
    const countEl = document.getElementById('char-count-num');
    const badgeEl = document.getElementById('char-count');
    
    if (textarea && countEl && badgeEl) {
        const length = textarea.value.length;
        countEl.textContent = length;
        
        // Update badge color
        badgeEl.classList.remove('bg-success', 'bg-warning', 'bg-danger');
        if (length > 160) {
            badgeEl.classList.add('bg-danger');
        } else if (length > 140) {
            badgeEl.classList.add('bg-warning');
        } else {
            badgeEl.classList.add('bg-success');
        }
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    updateCharCount();
});
</script>
</body>
</html>

