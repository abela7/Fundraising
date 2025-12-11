<?php
declare(strict_types=1);
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../config/db.php';
require_login();

$page_title = 'Fixed Issues';
$db = db();

// Check table
$table_exists = false;
try {
    $check = $db->query("SHOW TABLES LIKE 'security_fixes'");
    $table_exists = $check && $check->num_rows > 0;
} catch (Exception $e) {
    $table_exists = false;
}

$section_filter = $_GET['section'] ?? '';
$priority_filter = $_GET['priority'] ?? '';
$search = $_GET['search'] ?? '';

$fixes = [];
$stats = ['total' => 0, 'by_section' => []];

if ($table_exists) {
    $query = "SELECT * FROM security_fixes WHERE status = 'completed'";
    $params = [];
    $types = '';

    if ($section_filter !== '') {
        $query .= " AND section = ?";
        $params[] = $section_filter;
        $types .= 's';
    }

    if ($priority_filter !== '') {
        $query .= " AND priority = ?";
        $params[] = $priority_filter;
        $types .= 's';
    }

    if ($search !== '') {
        $query .= " AND (title LIKE ? OR description LIKE ? OR file_path LIKE ?)";
        $s = "%$search%";
        $params[] = $s; $params[] = $s; $params[] = $s;
        $types .= 'sss';
    }

    $query .= " ORDER BY updated_at DESC, priority ASC";

    $stmt = $db->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $fixes = $result->fetch_all(MYSQLI_ASSOC);

    // Stats
    $stats['total'] = count($fixes);
    foreach ($fixes as $fix) {
        $section = $fix['section'];
        $stats['by_section'][$section] = ($stats['by_section'][$section] ?? 0) + 1;
    }
    ksort($stats['by_section']);
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
        .fixed-card:hover { box-shadow: 0 4px 10px rgba(0,0,0,0.08); }
        .sticky-filters { position: sticky; top: 0; z-index: 9; background: #f8fafc; border: 1px solid #e9ecef; }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include 'includes/sidebar.php'; ?>
    <div class="admin-content">
        <?php include 'includes/topbar.php'; ?>
        <main class="main-content">
            <div class="container-fluid">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 mb-0">
                            <i class="fas fa-check-double text-success me-2"></i>
                            Fixed Issues
                        </h1>
                        <p class="text-muted mb-0">All issues marked as completed</p>
                    </div>
                    <div>
                        <a href="tools/fix_report.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Back to Fix Report
                        </a>
                    </div>
                </div>

                <?php if (!$table_exists): ?>
                    <div class="card">
                        <div class="card-body text-center py-5">
                            <i class="fas fa-database fa-3x text-muted mb-3"></i>
                            <h5>Database Setup Required</h5>
                            <p class="text-muted">Run the setup before viewing fixed issues.</p>
                            <a class="btn btn-primary" href="tools/setup_fix_tracker.php">
                                <i class="fas fa-cog me-2"></i>Setup Database
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="row g-4 mb-4">
                        <div class="col-md-4">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h6 class="text-muted mb-1">Total Fixed</h6>
                                    <h3 class="mb-0"><?php echo $stats['total']; ?></h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h6 class="text-muted mb-2">By Section</h6>
                                    <?php if (empty($stats['by_section'])): ?>
                                        <p class="text-muted mb-0">No completed fixes yet.</p>
                                    <?php else: ?>
                                        <div class="d-flex flex-wrap gap-2">
                                            <?php foreach ($stats['by_section'] as $sec => $count): ?>
                                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                                                    <?php echo htmlspecialchars($sec); ?>: <?php echo $count; ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card mb-4 sticky-filters">
                        <div class="card-body">
                            <form class="row g-3 align-items-end">
                                <div class="col-md-3">
                                    <label class="form-label">Section</label>
                                    <select name="section" class="form-select">
                                        <option value="">All</option>
                                        <?php foreach ($stats['by_section'] as $sec => $_): ?>
                                            <option value="<?php echo htmlspecialchars($sec); ?>" <?php echo $section_filter === $sec ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($sec); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Priority</label>
                                    <select name="priority" class="form-select">
                                        <option value="">All</option>
                                        <option value="critical" <?php echo $priority_filter === 'critical' ? 'selected' : ''; ?>>Critical</option>
                                        <option value="high" <?php echo $priority_filter === 'high' ? 'selected' : ''; ?>>High</option>
                                        <option value="medium" <?php echo $priority_filter === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                        <option value="low" <?php echo $priority_filter === 'low' ? 'selected' : ''; ?>>Low</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Search</label>
                                    <input type="text" name="search" class="form-control" value="<?php echo htmlspecialchars($search); ?>" placeholder="Title, description, file">
                                </div>
                                <div class="col-md-2 d-flex gap-2">
                                    <button class="btn btn-primary w-100" type="submit"><i class="fas fa-filter me-2"></i>Filter</button>
                                    <a class="btn btn-outline-secondary w-100" href="fixed_issues.php"><i class="fas fa-times"></i></a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <?php if (empty($fixes)): ?>
                        <div class="card">
                            <div class="card-body text-center py-5">
                                <i class="fas fa-box-open fa-3x text-muted mb-3"></i>
                                <h5>No completed fixes found</h5>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="row g-3">
                            <?php foreach ($fixes as $fix): ?>
                                <div class="col-12">
                                    <div class="card fixed-card">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <div>
                                                    <div class="d-flex align-items-center gap-2 mb-1">
                                                        <span class="badge bg-success">Completed</span>
                                                        <span class="badge bg-secondary"><?php echo htmlspecialchars($fix['section']); ?></span>
                                                        <span class="badge bg-info text-dark"><?php echo htmlspecialchars($fix['priority']); ?></span>
                                                    </div>
                                                    <h6 class="mb-1"><?php echo htmlspecialchars($fix['title']); ?></h6>
                                                    <small class="text-muted"><?php echo htmlspecialchars($fix['file_path']); ?></small>
                                                </div>
                                                <div class="text-end">
                                                    <small class="text-muted">
                                                        Completed: <?php echo $fix['completed_at'] ? date('M j, Y', strtotime($fix['completed_at'])) : '-'; ?><br>
                                                        Updated: <?php echo date('M j, Y', strtotime($fix['updated_at'] ?? $fix['created_at'])); ?>
                                                    </small>
                                                </div>
                                            </div>
                                            <p class="text-muted mb-2"><?php echo nl2br(htmlspecialchars($fix['description'])); ?></p>
                                            <?php if (!empty($fix['notes'])): ?>
                                                <div class="alert alert-primary py-2 mb-0">
                                                    <strong>Notes:</strong> <?php echo nl2br(htmlspecialchars($fix['notes'])); ?>
                                                </div>
                                            <?php endif; ?>
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
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
