<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/audit_helper.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

$db = db();
$page_title = 'Delete Church';

$church_id = (int)($_GET['id'] ?? 0);
$confirm = $_GET['confirm'] ?? '';

if ($church_id <= 0) {
    header("Location: churches.php?error=" . urlencode("Invalid church ID."));
    exit;
}

// Fetch church data
try {
    $stmt = $db->prepare("SELECT * FROM churches WHERE id = ?");
    $stmt->bind_param("i", $church_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $church = $result->fetch_assoc();
    
    if (!$church) {
        header("Location: churches.php?error=" . urlencode("Church not found."));
        exit;
    }
    
    // Check dependencies
    $reps_stmt = $db->prepare("SELECT COUNT(*) as count FROM church_representatives WHERE church_id = ?");
    $reps_stmt->bind_param("i", $church_id);
    $reps_stmt->execute();
    $reps_count = $reps_stmt->get_result()->fetch_assoc()['count'];
    
    $donors_stmt = $db->prepare("SELECT COUNT(*) as count FROM donors WHERE church_id = ?");
    $donors_stmt->bind_param("i", $church_id);
    $donors_stmt->execute();
    $donors_count = $donors_stmt->get_result()->fetch_assoc()['count'];
    
} catch (Exception $e) {
    header("Location: churches.php?error=" . urlencode("Error loading church: " . $e->getMessage()));
    exit;
}

// Handle deletion
if ($confirm === 'yes' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db->begin_transaction();
        
        // Delete representatives (CASCADE should handle this, but being explicit)
        if ($reps_count > 0) {
            $delete_reps = $db->prepare("DELETE FROM church_representatives WHERE church_id = ?");
            $delete_reps->bind_param("i", $church_id);
            $delete_reps->execute();
        }
        
        // Unlink donors (set church_id to NULL)
        if ($donors_count > 0) {
            $unlink_donors = $db->prepare("UPDATE donors SET church_id = NULL WHERE church_id = ?");
            $unlink_donors->bind_param("i", $church_id);
            $unlink_donors->execute();
        }
        
        // Audit log before deletion
        log_audit(
            $db,
            'delete',
            'church',
            $church_id,
            ['name' => $church['name'], 'city' => $church['city'], 'representatives_count' => $reps_count, 'donors_count' => $donors_count],
            null,
            'admin_portal',
            (int)($_SESSION['user']['id'] ?? 0)
        );
        
        // Delete church
        $delete_stmt = $db->prepare("DELETE FROM churches WHERE id = ?");
        $delete_stmt->bind_param("i", $church_id);
        $delete_stmt->execute();
        
        $db->commit();
        
        header("Location: churches.php?success=" . urlencode("Church deleted successfully."));
        exit;
        
    } catch (Exception $e) {
        $db->rollback();
        header("Location: churches.php?error=" . urlencode("Error deleting church: " . $e->getMessage()));
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $page_title; ?> - Fundraising System</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        :root {
            --church-primary: #0a6286;
            --church-danger: #dc3545;
        }
        
        .page-header {
            background: linear-gradient(135deg, var(--church-danger) 0%, #c82333 100%);
            color: white;
            padding: 2rem 1.5rem;
            margin-bottom: 2rem;
            border-radius: 12px;
        }
        
        .warning-card {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            border-left: 4px solid var(--church-danger);
        }
        
        .warning-icon {
            font-size: 4rem;
            color: var(--church-danger);
            margin-bottom: 1rem;
        }
        
        .info-card {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        @media (max-width: 768px) {
            .page-header {
                padding: 1.5rem 1rem;
            }
            
            .page-header h1 {
                font-size: 1.5rem;
            }
            
            .warning-card {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include __DIR__ . '/../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="container-fluid">
                
                <!-- Page Header -->
                <div class="page-header">
                    <div class="d-flex justify-content-between align-items-center flex-wrap">
                        <div>
                            <h1 class="mb-2"><i class="fas fa-exclamation-triangle me-2"></i>Delete Church</h1>
                            <p class="mb-0 opacity-75">Confirm deletion of church</p>
                        </div>
                        <a href="churches.php" class="btn btn-light btn-lg mt-2 mt-md-0">
                            <i class="fas fa-arrow-left me-2"></i>Cancel
                        </a>
                    </div>
                </div>
                
                <!-- Warning Card -->
                <div class="warning-card">
                    <div class="text-center mb-4">
                        <div class="warning-icon">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <h3>Are you sure you want to delete this church?</h3>
                        <p class="text-muted">This action cannot be undone.</p>
                    </div>
                    
                    <!-- Church Info -->
                    <div class="info-card">
                        <h5 class="mb-3">Church Details</h5>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <strong>Name:</strong><br>
                                <?php echo htmlspecialchars($church['name']); ?>
                            </div>
                            <div class="col-md-6">
                                <strong>City:</strong><br>
                                <?php echo htmlspecialchars($church['city']); ?>
                            </div>
                            <?php if ($church['address']): ?>
                            <div class="col-md-12">
                                <strong>Address:</strong><br>
                                <?php echo htmlspecialchars($church['address']); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Dependencies Warning -->
                    <?php if ($reps_count > 0 || $donors_count > 0): ?>
                    <div class="alert alert-warning">
                        <h6><i class="fas fa-info-circle me-2"></i>This church has dependencies:</h6>
                        <ul class="mb-0">
                            <?php if ($reps_count > 0): ?>
                            <li><strong><?php echo $reps_count; ?></strong> representative<?php echo $reps_count > 1 ? 's' : ''; ?> will be deleted.</li>
                            <?php endif; ?>
                            <?php if ($donors_count > 0): ?>
                            <li><strong><?php echo $donors_count; ?></strong> donor<?php echo $donors_count > 1 ? 's' : ''; ?> will be unlinked from this church.</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Delete Form -->
                    <form method="POST" action="?id=<?php echo $church_id; ?>&confirm=yes">
                        <div class="d-flex gap-2 justify-content-end mt-4">
                            <a href="churches.php" class="btn btn-outline-secondary">
                                <i class="fas fa-times me-2"></i>Cancel
                            </a>
                            <button type="submit" class="btn btn-danger">
                                <i class="fas fa-trash me-2"></i>Yes, Delete Church
                            </button>
                        </div>
                    </form>
                </div>
                
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
</body>
</html>

