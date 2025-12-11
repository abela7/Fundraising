<?php
declare(strict_types=1);
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../shared/ReportParser.php';
require_login();

$page_title = 'Security Fix Report';
$db = db();

// Handle AJAX requests for updating fix status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'update_status') {
            $fix_id = (int)($_POST['fix_id'] ?? 0);
            $status = $_POST['status'] ?? '';
            $notes = trim($_POST['notes'] ?? '');
            $assigned_to = trim($_POST['assigned_to'] ?? '');

            if (!in_array($status, ['pending', 'in_progress', 'completed', 'cancelled'])) {
                throw new Exception('Invalid status');
            }

            $update_fields = ['status = ?'];
            $params = [$status];
            $types = 's';

            if (!empty($notes)) {
                $update_fields[] = 'notes = ?';
                $params[] = $notes;
                $types .= 's';
            }

            if (!empty($assigned_to)) {
                $update_fields[] = 'assigned_to = ?';
                $params[] = $assigned_to;
                $types .= 's';
            }

            if ($status === 'completed') {
                $update_fields[] = 'completed_at = NOW()';
            }

            $params[] = $fix_id;
            $types .= 'i';

            $query = "UPDATE security_fixes SET " . implode(', ', $update_fields) . " WHERE id = ?";
            $stmt = $db->prepare($query);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();

            echo json_encode(['success' => true]);
            exit;
        }

        if ($action === 'populate_from_report') {
            // Check if table exists
            $table_check = $db->query("SHOW TABLES LIKE 'security_fixes'");
            if (!$table_check || $table_check->num_rows === 0) {
                echo json_encode(['success' => false, 'message' => 'Security fixes table does not exist. Please run database setup first.']);
                exit;
            }

            // Check if table is already populated
            $count_result = $db->query("SELECT COUNT(*) as count FROM security_fixes");
            $count = $count_result->fetch_assoc()['count'];

            if ($count > 0) {
                echo json_encode(['success' => false, 'message' => 'Table already contains data. Clear it first if you want to re-import.']);
                exit;
            }

            $parser = new ReportParser();
            $inserts = $parser->getDatabaseInserts();

            if (empty($inserts)) {
                echo json_encode(['success' => false, 'message' => 'No fixes found in report.md. Please check the file exists and is readable.']);
                exit;
            }

            $inserted = 0;
            $stmt = $db->prepare("INSERT INTO security_fixes (section, file_path, issue_type, title, description, priority, status) VALUES (?, ?, ?, ?, ?, ?, ?)");

            foreach ($inserts as $insert) {
                $stmt->bind_param('sssssss',
                    $insert['section'],
                    $insert['file_path'],
                    $insert['issue_type'],
                    $insert['title'],
                    $insert['description'],
                    $insert['priority'],
                    $insert['status']
                );
                $stmt->execute();
                $inserted++;
            }

            echo json_encode(['success' => true, 'message' => "Imported $inserted fixes from report.md"]);
            exit;
        }

        if ($action === 'clear_all') {
            // Check if table exists
            $table_check = $db->query("SHOW TABLES LIKE 'security_fixes'");
            if (!$table_check || $table_check->num_rows === 0) {
                echo json_encode(['success' => false, 'message' => 'Security fixes table does not exist.']);
                exit;
            }

            $db->query("TRUNCATE TABLE security_fixes");
            echo json_encode(['success' => true, 'message' => 'All fixes cleared']);
            exit;
        }

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// Check if security_fixes table exists
$table_exists = false;
try {
    $check_result = $db->query("SHOW TABLES LIKE 'security_fixes'");
    $table_exists = $check_result && $check_result->num_rows > 0;
} catch (Exception $e) {
    $table_exists = false;
}

$fixes = [];
$stats = ['total' => 0, 'completed' => 0, 'in_progress' => 0, 'pending' => 0, 'critical_count' => 0, 'critical_issues' => 0];
$fixes_by_section = [];

if ($table_exists) {
    // Get filter parameters
    $section_filter = $_GET['section'] ?? '';
    $status_filter = $_GET['status'] ?? '';
    $priority_filter = $_GET['priority'] ?? '';
    $search = $_GET['search'] ?? '';

    // Build query
    $query = "SELECT * FROM security_fixes WHERE 1=1";
    $params = [];
    $types = '';

    if (!empty($section_filter)) {
        $query .= " AND section = ?";
        $params[] = $section_filter;
        $types .= 's';
    }

    if (!empty($status_filter)) {
        $query .= " AND status = ?";
        $params[] = $status_filter;
        $types .= 's';
    }

    if (!empty($priority_filter)) {
        $query .= " AND priority = ?";
        $params[] = $priority_filter;
        $types .= 's';
    }

    if (!empty($search)) {
        $query .= " AND (title LIKE ? OR description LIKE ? OR file_path LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= 'sss';
    }

    $query .= " ORDER BY
        CASE priority
            WHEN 'critical' THEN 1
            WHEN 'high' THEN 2
            WHEN 'medium' THEN 3
            WHEN 'low' THEN 4
        END,
        CASE status
            WHEN 'pending' THEN 1
            WHEN 'in_progress' THEN 2
            WHEN 'completed' THEN 3
            WHEN 'cancelled' THEN 4
        END,
        section, file_path";

    $stmt = $db->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $fixes = $result->fetch_all(MYSQLI_ASSOC);

    // Get summary statistics
    $stats_query = "SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN priority = 'critical' THEN 1 ELSE 0 END) as critical_count,
        SUM(CASE WHEN issue_type = 'critical' THEN 1 ELSE 0 END) as critical_issues
        FROM security_fixes";
    $stats_result = $db->query($stats_query);
    $stats = $stats_result->fetch_assoc();

    // Group fixes by section
    $fixes_by_section = [];
    foreach ($fixes as $fix) {
        $section = $fix['section'];
        if (!isset($fixes_by_section[$section])) {
            $fixes_by_section[$section] = [];
        }
        $fixes_by_section[$section][] = $fix;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($page_title); ?> - Fundraising Admin</title>
    <link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="assets/admin.css">
    <style>
        .status-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }
        .priority-critical { background-color: #dc3545; color: white; }
        .priority-high { background-color: #fd7e14; color: white; }
        .priority-medium { background-color: #ffc107; color: black; }
        .priority-low { background-color: #28a745; color: white; }
        .status-pending { background-color: #6c757d; color: white; }
        .status-in_progress { background-color: #007bff; color: white; }
        .status-completed { background-color: #28a745; color: white; }
        .status-cancelled { background-color: #dc3545; color: white; }
        .issue-critical { border-left: 4px solid #dc3545; }
        .issue-enhancement { border-left: 4px solid #007bff; }
        .section-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 0.5rem;
        }
        .fix-card {
            transition: all 0.3s ease;
        }
        .fix-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include 'includes/sidebar.php'; ?>

    <div class="admin-content">
        <?php include 'includes/topbar.php'; ?>

        <main class="main-content">
            <div class="container-fluid">
                <!-- Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 mb-0">
                            <i class="fas fa-shield-alt text-primary me-2"></i>
                            Security Fix Report
                        </h1>
                        <p class="text-muted mb-0">Track and manage security fixes from the review report</p>
                    </div>
                    <div>
                        <?php if ($table_exists): ?>
                            <button type="button" class="btn btn-outline-primary" onclick="populateFromReport()">
                                <i class="fas fa-upload me-2"></i>
                                Import from Report
                            </button>
                            <button type="button" class="btn btn-outline-danger" onclick="clearAllFixes()">
                                <i class="fas fa-trash me-2"></i>
                                Clear All
                            </button>
                        <?php else: ?>
                            <a href="tools/setup_fix_tracker.php" class="btn btn-outline-primary">
                                <i class="fas fa-cog me-2"></i>
                                Setup Database First
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="row g-4 mb-4">
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="card stat-card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="stat-icon bg-primary">
                                        <i class="fas fa-tasks"></i>
                                    </div>
                                    <div class="ms-3">
                                        <h3 class="stat-value"><?php echo $stats['total']; ?></h3>
                                        <p class="stat-label">Total Fixes</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="card stat-card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="stat-icon bg-success">
                                        <i class="fas fa-check-circle"></i>
                                    </div>
                                    <div class="ms-3">
                                        <h3 class="stat-value"><?php echo $stats['completed']; ?></h3>
                                        <p class="stat-label">Completed</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="card stat-card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="stat-icon bg-warning">
                                        <i class="fas fa-clock"></i>
                                    </div>
                                    <div class="ms-3">
                                        <h3 class="stat-value"><?php echo $stats['in_progress']; ?></h3>
                                        <p class="stat-label">In Progress</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="card stat-card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="stat-icon bg-danger">
                                        <i class="fas fa-exclamation-triangle"></i>
                                    </div>
                                    <div class="ms-3">
                                        <h3 class="stat-value"><?php echo $stats['critical_count']; ?></h3>
                                        <p class="stat-label">Critical Priority</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Filters</h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-3">
                                <label for="section" class="form-label">Section</label>
                                <select name="section" id="section" class="form-select">
                                    <option value="">All Sections</option>
                                    <?php
                                    $sections = array_keys($fixes_by_section);
                                    sort($sections);
                                    foreach ($sections as $section) {
                                        $selected = $section_filter === $section ? 'selected' : '';
                                        echo "<option value=\"$section\" $selected>$section</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label for="status" class="form-label">Status</label>
                                <select name="status" id="status" class="form-select">
                                    <option value="">All Status</option>
                                    <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                    <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                    <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label for="priority" class="form-label">Priority</label>
                                <select name="priority" id="priority" class="form-select">
                                    <option value="">All Priority</option>
                                    <option value="critical" <?php echo $priority_filter === 'critical' ? 'selected' : ''; ?>>Critical</option>
                                    <option value="high" <?php echo $priority_filter === 'high' ? 'selected' : ''; ?>>High</option>
                                    <option value="medium" <?php echo $priority_filter === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                    <option value="low" <?php echo $priority_filter === 'low' ? 'selected' : ''; ?>>Low</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="search" class="form-label">Search</label>
                                <input type="text" name="search" id="search" class="form-control" placeholder="Search fixes..." value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary me-2">
                                    <i class="fas fa-search me-2"></i>
                                    Filter
                                </button>
                                <a href="fix_report.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times"></i>
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Fixes by Section -->
                <?php if (!$table_exists): ?>
                    <div class="card">
                        <div class="card-body text-center py-5">
                            <i class="fas fa-database fa-3x text-muted mb-3"></i>
                            <h5>Database Setup Required</h5>
                            <p class="text-muted">The security fixes tracking table hasn't been created yet.</p>
                            <a href="tools/setup_fix_tracker.php" class="btn btn-primary">
                                <i class="fas fa-cog me-2"></i>
                                Setup Database
                            </a>
                        </div>
                    </div>
                <?php elseif (empty($fixes)): ?>
                    <div class="card">
                        <div class="card-body text-center py-5">
                            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                            <h5>No fixes found</h5>
                            <p class="text-muted">Import fixes from the report.md file to get started.</p>
                            <button type="button" class="btn btn-primary" onclick="populateFromReport()">
                                <i class="fas fa-upload me-2"></i>
                                Import from Report
                            </button>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($fixes_by_section as $section_name => $section_fixes): ?>
                        <div class="section-container mb-5">
                            <div class="section-header">
                                <h4 class="mb-0">
                                    <i class="fas fa-folder me-2"></i>
                                    <?php echo htmlspecialchars($section_name); ?> Section
                                    <span class="badge bg-light text-dark ms-2"><?php echo count($section_fixes); ?> fixes</span>
                                </h4>
                            </div>

                            <div class="row g-4">
                                <?php
                                // Group by file
                                $fixes_by_file = [];
                                foreach ($section_fixes as $fix) {
                                    $file = $fix['file_path'];
                                    if (!isset($fixes_by_file[$file])) {
                                        $fixes_by_file[$file] = [];
                                    }
                                    $fixes_by_file[$file][] = $fix;
                                }

                                foreach ($fixes_by_file as $file_path => $file_fixes):
                                ?>
                                    <div class="col-12">
                                        <div class="card">
                                            <div class="card-header d-flex justify-content-between align-items-center">
                                                <h6 class="mb-0">
                                                    <i class="fas fa-file-code me-2"></i>
                                                    <?php echo htmlspecialchars($file_path); ?>
                                                </h6>
                                                <span class="badge bg-secondary"><?php echo count($file_fixes); ?> issues</span>
                                            </div>
                                            <div class="card-body">
                                                <div class="row g-3">
                                                    <?php foreach ($file_fixes as $fix): ?>
                                                        <div class="col-12">
                                                            <div class="fix-card card border-left-<?php echo $fix['issue_type'] === 'critical' ? 'danger' : 'primary'; ?>">
                                                                <div class="card-body">
                                                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                                                        <div class="flex-grow-1">
                                                                            <h6 class="card-title mb-1">
                                                                                <?php echo htmlspecialchars($fix['title']); ?>
                                                                                <?php if ($fix['issue_type'] === 'critical'): ?>
                                                                                    <span class="badge bg-danger ms-2">Critical Issue</span>
                                                                                <?php else: ?>
                                                                                    <span class="badge bg-info ms-2">Enhancement</span>
                                                                                <?php endif; ?>
                                                                            </h6>
                                                                            <p class="card-text small text-muted mb-2"><?php echo nl2br(htmlspecialchars($fix['description'])); ?></p>
                                                                        </div>
                                                                        <div class="ms-3">
                                                                            <span class="badge priority-<?php echo $fix['priority']; ?> status-badge">
                                                                                <?php echo ucfirst($fix['priority']); ?>
                                                                            </span>
                                                                        </div>
                                                                    </div>

                                                                    <div class="row g-2 mb-3">
                                                                        <div class="col-md-3">
                                                                            <small class="text-muted">Status</small>
                                                                            <select class="form-select form-select-sm status-select" data-fix-id="<?php echo $fix['id']; ?>">
                                                                                <option value="pending" <?php echo $fix['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                                                                <option value="in_progress" <?php echo $fix['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                                                                <option value="completed" <?php echo $fix['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                                                                <option value="cancelled" <?php echo $fix['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                                                            </select>
                                                                        </div>
                                                                        <div class="col-md-3">
                                                                            <small class="text-muted">Assigned To</small>
                                                                            <input type="text" class="form-control form-control-sm assigned-input"
                                                                                   data-fix-id="<?php echo $fix['id']; ?>"
                                                                                   placeholder="Assign to..."
                                                                                   value="<?php echo htmlspecialchars($fix['assigned_to'] ?? ''); ?>">
                                                                        </div>
                                                                        <div class="col-md-6">
                                                                            <small class="text-muted">Notes</small>
                                                                            <input type="text" class="form-control form-control-sm notes-input"
                                                                                   data-fix-id="<?php echo $fix['id']; ?>"
                                                                                   placeholder="Add notes..."
                                                                                   value="<?php echo htmlspecialchars($fix['notes'] ?? ''); ?>">
                                                                        </div>
                                                                    </div>

                                                                    <div class="d-flex justify-content-between align-items-center">
                                                                        <small class="text-muted">
                                                                            Created: <?php echo date('M j, Y', strtotime($fix['created_at'])); ?>
                                                                            <?php if ($fix['completed_at']): ?>
                                                                                | Completed: <?php echo date('M j, Y', strtotime($fix['completed_at'])); ?>
                                                                            <?php endif; ?>
                                                                        </small>
                                                                        <button type="button" class="btn btn-sm btn-outline-primary save-btn"
                                                                                data-fix-id="<?php echo $fix['id']; ?>" style="display: none;">
                                                                            <i class="fas fa-save me-1"></i>
                                                                            Save
                                                                        </button>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/admin.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Handle status changes
    document.querySelectorAll('.status-select').forEach(select => {
        select.addEventListener('change', function() {
            const fixId = this.dataset.fixId;
            showSaveButton(fixId);
        });
    });

    // Handle assigned to changes
    document.querySelectorAll('.assigned-input').forEach(input => {
        input.addEventListener('input', function() {
            const fixId = this.dataset.fixId;
            showSaveButton(fixId);
        });
    });

    // Handle notes changes
    document.querySelectorAll('.notes-input').forEach(input => {
        input.addEventListener('input', function() {
            const fixId = this.dataset.fixId;
            showSaveButton(fixId);
        });
    });

    // Handle save button clicks
    document.querySelectorAll('.save-btn').forEach(button => {
        button.addEventListener('click', function() {
            const fixId = this.dataset.fixId;
            saveFix(fixId);
        });
    });
});

function showSaveButton(fixId) {
    const saveBtn = document.querySelector(`.save-btn[data-fix-id="${fixId}"]`);
    if (saveBtn) {
        saveBtn.style.display = 'inline-block';
    }
}

function saveFix(fixId) {
    const statusSelect = document.querySelector(`.status-select[data-fix-id="${fixId}"]`);
    const assignedInput = document.querySelector(`.assigned-input[data-fix-id="${fixId}"]`);
    const notesInput = document.querySelector(`.notes-input[data-fix-id="${fixId}"]`);
    const saveBtn = document.querySelector(`.save-btn[data-fix-id="${fixId}"]`);

    const status = statusSelect.value;
    const assignedTo = assignedInput.value.trim();
    const notes = notesInput.value.trim();

    // Show loading state
    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Saving...';
    saveBtn.disabled = true;

    fetch('fix_report.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
            action: 'update_status',
            fix_id: fixId,
            status: status,
            assigned_to: assignedTo,
            notes: notes
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            saveBtn.style.display = 'none';
            // Show success message
            showToast('Fix updated successfully!', 'success');
        } else {
            showToast('Error: ' + data.message, 'danger');
        }
    })
    .catch(error => {
        showToast('Error updating fix', 'danger');
    })
    .finally(() => {
        saveBtn.innerHTML = '<i class="fas fa-save me-1"></i> Save';
        saveBtn.disabled = false;
    });
}

function populateFromReport() {
    if (!confirm('This will import all fixes from report.md. Continue?')) {
        return;
    }

    const button = event.target.closest('button');
    const originalText = button.innerHTML;
    button.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Importing...';
    button.disabled = true;

    fetch('fix_report.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
            action: 'populate_from_report'
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast(data.message, 'success');
            setTimeout(() => location.reload(), 2000);
        } else {
            showToast(data.message, 'danger');
        }
    })
    .catch(error => {
        showToast('Error importing fixes', 'danger');
    })
    .finally(() => {
        button.innerHTML = originalText;
        button.disabled = false;
    });
}

function clearAllFixes() {
    if (!confirm('This will delete ALL fixes. This cannot be undone. Continue?')) {
        return;
    }

    const button = event.target.closest('button');
    const originalText = button.innerHTML;
    button.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Clearing...';
    button.disabled = false;

    fetch('fix_report.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
            action: 'clear_all'
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast(data.message, 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast(data.message, 'danger');
        }
    })
    .catch(error => {
        showToast('Error clearing fixes', 'danger');
    })
    .finally(() => {
        button.innerHTML = originalText;
        button.disabled = false;
    });
}

function showToast(message, type) {
    // Create toast element
    const toast = document.createElement('div');
    toast.className = `toast align-items-center text-white bg-${type} border-0`;
    toast.setAttribute('role', 'alert');
    toast.innerHTML = `
        <div class="d-flex">
            <div class="toast-body">${message}</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    `;

    // Add to page
    const container = document.querySelector('.admin-content') || document.body;
    container.appendChild(toast);

    // Initialize and show
    const bsToast = new bootstrap.Toast(toast);
    bsToast.show();

    // Remove after hide
    toast.addEventListener('hidden.bs.toast', () => {
        toast.remove();
    });
}
</script>
</body>
</html>