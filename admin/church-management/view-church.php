<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

$db = db();
$page_title = 'View Church';

$church_id = (int)($_GET['id'] ?? 0);

if ($church_id <= 0) {
    header("Location: churches.php?error=" . urlencode("Invalid church ID."));
    exit;
}

try {
    // Fetch church data
    $stmt = $db->prepare("SELECT * FROM churches WHERE id = ?");
    $stmt->bind_param("i", $church_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $church = $result->fetch_assoc();
    
    if (!$church) {
        header("Location: churches.php?error=" . urlencode("Church not found."));
        exit;
    }
    
    // Fetch representatives
    $reps_stmt = $db->prepare("
        SELECT * FROM church_representatives 
        WHERE church_id = ? 
        ORDER BY is_primary DESC, name ASC
    ");
    $reps_stmt->bind_param("i", $church_id);
    $reps_stmt->execute();
    $representatives = $reps_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Fetch assigned donors count
    $donors_stmt = $db->prepare("SELECT COUNT(*) as count FROM donors WHERE church_id = ?");
    $donors_stmt->bind_param("i", $church_id);
    $donors_stmt->execute();
    $donors_count = $donors_stmt->get_result()->fetch_assoc()['count'];
    
} catch (Exception $e) {
    header("Location: churches.php?error=" . urlencode("Error loading church: " . $e->getMessage()));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($church['name']); ?> - Fundraising System</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        :root {
            --church-primary: #0a6286;
        }
        
        .page-header {
            background: linear-gradient(135deg, var(--church-primary) 0%, #084767 100%);
            color: white;
            padding: 2rem 1.5rem;
            margin-bottom: 2rem;
            border-radius: 12px;
        }
        
        .info-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .info-card h5 {
            color: var(--church-primary);
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .info-row {
            display: flex;
            padding: 0.75rem 0;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .info-row:last-child {
            border-bottom: none;
        }
        
        .info-label {
            font-weight: 600;
            color: #64748b;
            min-width: 150px;
        }
        
        .info-value {
            color: #1e293b;
            flex: 1;
        }
        
        .rep-card {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 0.75rem;
            border-left: 3px solid var(--church-primary);
        }
        
        .rep-card.primary {
            border-left-color: #10b981;
            background: #f0fdf4;
        }
        
        @media (max-width: 768px) {
            .page-header {
                padding: 1.5rem 1rem;
            }
            
            .page-header h1 {
                font-size: 1.5rem;
            }
            
            .info-card {
                padding: 1rem;
            }
            
            .info-row {
                flex-direction: column;
            }
            
            .info-label {
                min-width: auto;
                margin-bottom: 0.25rem;
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
                            <h1 class="mb-2"><i class="fas fa-church me-2"></i><?php echo htmlspecialchars($church['name']); ?></h1>
                            <p class="mb-0 opacity-75">
                                <?php if ($church['is_active']): ?>
                                    <span class="badge bg-success me-2">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary me-2">Inactive</span>
                                <?php endif; ?>
                                Church Details
                            </p>
                        </div>
                        <div class="d-flex gap-2 mt-2 mt-md-0 flex-wrap">
                            <a href="edit-church.php?id=<?php echo $church_id; ?>" class="btn btn-light">
                                <i class="fas fa-edit me-2"></i>Edit
                            </a>
                            <a href="delete-church.php?id=<?php echo $church_id; ?>" class="btn btn-light text-danger">
                                <i class="fas fa-trash me-2"></i>Delete
                            </a>
                            <a href="churches.php" class="btn btn-light">
                                <i class="fas fa-arrow-left me-2"></i>Back to List
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Church Information -->
                <div class="info-card">
                    <h5><i class="fas fa-info-circle me-2"></i>Church Information</h5>
                    <div class="info-row">
                        <div class="info-label">Church Name</div>
                        <div class="info-value"><?php echo htmlspecialchars($church['name']); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">City</div>
                        <div class="info-value">
                            <span class="badge bg-info"><?php echo htmlspecialchars($church['city']); ?></span>
                        </div>
                    </div>
                    <?php if ($church['address']): ?>
                    <div class="info-row">
                        <div class="info-label">Address</div>
                        <div class="info-value"><?php echo htmlspecialchars($church['address']); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($church['phone']): ?>
                    <div class="info-row">
                        <div class="info-label">Phone</div>
                        <div class="info-value">
                            <a href="tel:<?php echo htmlspecialchars($church['phone']); ?>" class="text-decoration-none">
                                <i class="fas fa-phone me-2"></i><?php echo htmlspecialchars($church['phone']); ?>
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="info-row">
                        <div class="info-label">Status</div>
                        <div class="info-value">
                            <?php if ($church['is_active']): ?>
                                <span class="badge bg-success">Active</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Inactive</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Created</div>
                        <div class="info-value"><?php echo date('F j, Y g:i A', strtotime($church['created_at'])); ?></div>
                    </div>
                    <?php if ($church['updated_at'] && $church['updated_at'] !== $church['created_at']): ?>
                    <div class="info-row">
                        <div class="info-label">Last Updated</div>
                        <div class="info-value"><?php echo date('F j, Y g:i A', strtotime($church['updated_at'])); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Statistics -->
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="info-card text-center">
                            <h5><i class="fas fa-user-tie me-2"></i>Representatives</h5>
                            <div class="display-4 text-primary"><?php echo count($representatives); ?></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="info-card text-center">
                            <h5><i class="fas fa-users me-2"></i>Assigned Donors</h5>
                            <div class="display-4 text-success"><?php echo $donors_count; ?></div>
                            <?php if ($donors_count > 0): ?>
                            <a href="church-assigned-donors.php?church_id=<?php echo $church_id; ?>" class="btn btn-sm btn-outline-primary mt-2">
                                <i class="fas fa-eye me-1"></i>View All Assigned Donors
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Representatives -->
                <div class="info-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0"><i class="fas fa-user-tie me-2"></i>Representatives</h5>
                        <a href="representatives.php?church_id=<?php echo $church_id; ?>" class="btn btn-sm btn-primary">
                            <i class="fas fa-plus me-1"></i>Add Representative
                        </a>
                    </div>
                    
                    <?php if (empty($representatives)): ?>
                        <p class="text-muted text-center py-3">No representatives assigned yet.</p>
                    <?php else: ?>
                        <?php foreach ($representatives as $rep): ?>
                        <div class="rep-card <?php echo $rep['is_primary'] ? 'primary' : ''; ?>">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h6 class="mb-1">
                                        <?php echo htmlspecialchars($rep['name']); ?>
                                        <?php if ($rep['is_primary']): ?>
                                            <span class="badge bg-success ms-2">Primary</span>
                                        <?php endif; ?>
                                    </h6>
                                    <p class="mb-1 small text-muted">
                                        <span class="badge bg-light text-dark"><?php echo htmlspecialchars($rep['role']); ?></span>
                                    </p>
                                    <?php if ($rep['phone']): ?>
                                    <p class="mb-0 small">
                                        <i class="fas fa-phone me-1"></i>
                                        <a href="tel:<?php echo htmlspecialchars($rep['phone']); ?>" class="text-decoration-none">
                                            <?php echo htmlspecialchars($rep['phone']); ?>
                                        </a>
                                    </p>
                                    <?php endif; ?>
                                    <?php if ($rep['email']): ?>
                                    <p class="mb-0 small">
                                        <i class="fas fa-envelope me-1"></i>
                                        <a href="mailto:<?php echo htmlspecialchars($rep['email']); ?>" class="text-decoration-none">
                                            <?php echo htmlspecialchars($rep['email']); ?>
                                        </a>
                                    </p>
                                    <?php endif; ?>
                                </div>
                                <?php if (!$rep['is_active']): ?>
                                    <span class="badge bg-secondary">Inactive</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
</body>
</html>

