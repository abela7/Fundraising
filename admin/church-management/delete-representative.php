<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/audit_helper.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

$db = db();
$page_title = 'Delete Representative';

$rep_id = (int)($_GET['id'] ?? 0);
$confirm = $_GET['confirm'] ?? '';

if ($rep_id <= 0) {
    header("Location: representatives.php?error=" . urlencode("Invalid representative ID."));
    exit;
}

// Fetch representative data
try {
    $stmt = $db->prepare("
        SELECT cr.*, c.name as church_name, c.city as church_city
        FROM church_representatives cr
        INNER JOIN churches c ON cr.church_id = c.id
        WHERE cr.id = ?
    ");
    $stmt->bind_param("i", $rep_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $rep = $result->fetch_assoc();
    
    if (!$rep) {
        header("Location: representatives.php?error=" . urlencode("Representative not found."));
        exit;
    }
    
} catch (Exception $e) {
    header("Location: representatives.php?error=" . urlencode("Error loading representative: " . $e->getMessage()));
    exit;
}

// Handle deletion
if ($confirm === 'yes' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Audit log before deletion
        log_audit(
            $db,
            'delete',
            'church_representative',
            $rep_id,
            ['name' => $rep['name'], 'role' => $rep['role'], 'church_name' => $rep['church_name']],
            null,
            'admin_portal',
            (int)($_SESSION['user']['id'] ?? 0)
        );
        
        $delete_stmt = $db->prepare("DELETE FROM church_representatives WHERE id = ?");
        $delete_stmt->bind_param("i", $rep_id);
        $delete_stmt->execute();
        
        header("Location: representatives.php?success=" . urlencode("Representative deleted successfully."));
        exit;
        
    } catch (Exception $e) {
        header("Location: representatives.php?error=" . urlencode("Error deleting representative: " . $e->getMessage()));
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
            --rep-primary: #0a6286;
            --rep-danger: #dc3545;
        }
        
        .page-header {
            background: linear-gradient(135deg, var(--rep-danger) 0%, #c82333 100%);
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
            border-left: 4px solid var(--rep-danger);
        }
        
        .warning-icon {
            font-size: 4rem;
            color: var(--rep-danger);
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
                            <h1 class="mb-2"><i class="fas fa-exclamation-triangle me-2"></i>Delete Representative</h1>
                            <p class="mb-0 opacity-75">Confirm deletion of representative</p>
                        </div>
                        <a href="representatives.php" class="btn btn-light btn-lg mt-2 mt-md-0">
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
                        <h3>Are you sure you want to delete this representative?</h3>
                        <p class="text-muted">This action cannot be undone.</p>
                    </div>
                    
                    <!-- Representative Info -->
                    <div class="info-card">
                        <h5 class="mb-3">Representative Details</h5>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <strong>Name:</strong><br>
                                <?php echo htmlspecialchars($rep['name']); ?>
                            </div>
                            <div class="col-md-6">
                                <strong>Role:</strong><br>
                                <?php echo htmlspecialchars($rep['role']); ?>
                            </div>
                            <div class="col-md-6">
                                <strong>Church:</strong><br>
                                <?php echo htmlspecialchars($rep['church_name']); ?><br>
                                <small class="text-muted"><?php echo htmlspecialchars($rep['church_city']); ?></small>
                            </div>
                            <div class="col-md-6">
                                <strong>Phone:</strong><br>
                                <?php echo htmlspecialchars($rep['phone']); ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Delete Form -->
                    <form method="POST" action="?id=<?php echo $rep_id; ?>&confirm=yes">
                        <div class="d-flex gap-2 justify-content-end mt-4">
                            <a href="representatives.php" class="btn btn-outline-secondary">
                                <i class="fas fa-times me-2"></i>Cancel
                            </a>
                            <button type="submit" class="btn btn-danger">
                                <i class="fas fa-trash me-2"></i>Yes, Delete Representative
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

