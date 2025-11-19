<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

$db = db();
$page_title = 'View Representative';

$rep_id = (int)($_GET['id'] ?? 0);

if ($rep_id <= 0) {
    header("Location: representatives.php?error=" . urlencode("Invalid representative ID."));
    exit;
}

try {
    // Fetch representative data
    $stmt = $db->prepare("
        SELECT cr.*, c.name as church_name, c.city as church_city, c.address as church_address, c.phone as church_phone
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
    
    // Fetch assigned donors (from same church)
    $donors_stmt = $db->prepare("
        SELECT 
            d.id,
            d.name,
            d.phone,
            d.total_pledged,
            d.total_paid,
            d.balance,
            d.payment_status,
            d.created_at
        FROM donors d
        WHERE d.church_id = ?
        ORDER BY d.name ASC
        LIMIT 50
    ");
    $donors_stmt->bind_param("i", $rep['church_id']);
    $donors_stmt->execute();
    $donors = $donors_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Count total donors
    $donors_count_stmt = $db->prepare("SELECT COUNT(*) as count FROM donors WHERE church_id = ?");
    $donors_count_stmt->bind_param("i", $rep['church_id']);
    $donors_count_stmt->execute();
    $donors_count = $donors_count_stmt->get_result()->fetch_assoc()['count'];
    
    // Get other representatives from same church
    $other_reps_stmt = $db->prepare("
        SELECT id, name, role, is_primary 
        FROM church_representatives 
        WHERE church_id = ? AND id != ? AND is_active = 1
        ORDER BY is_primary DESC, name ASC
    ");
    $other_reps_stmt->bind_param("ii", $rep['church_id'], $rep_id);
    $other_reps_stmt->execute();
    $other_reps = $other_reps_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
} catch (Exception $e) {
    header("Location: representatives.php?error=" . urlencode("Error loading representative: " . $e->getMessage()));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($rep['name']); ?> - Fundraising System</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        :root {
            --rep-primary: #0a6286;
        }
        
        .page-header {
            background: linear-gradient(135deg, var(--rep-primary) 0%, #084767 100%);
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
            color: var(--rep-primary);
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
        
        .donor-item {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 0.75rem;
            border-left: 3px solid var(--rep-primary);
            transition: all 0.2s ease;
        }
        
        .donor-item:hover {
            background: #f1f5f9;
            transform: translateX(4px);
        }
        
        .donor-stats {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin-top: 0.5rem;
        }
        
        .stat-badge {
            font-size: 0.875rem;
            padding: 0.25rem 0.75rem;
            border-radius: 4px;
        }
        
        .accordion-button:not(.collapsed) {
            background-color: #f8fafc;
            color: var(--rep-primary);
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
            
            .donor-stats {
                flex-direction: column;
                gap: 0.5rem;
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
                            <h1 class="mb-2"><i class="fas fa-user-tie me-2"></i><?php echo htmlspecialchars($rep['name']); ?></h1>
                            <p class="mb-0 opacity-75">
                                <?php if ($rep['is_active']): ?>
                                    <span class="badge bg-success me-2">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary me-2">Inactive</span>
                                <?php endif; ?>
                                <?php if ($rep['is_primary']): ?>
                                    <span class="badge bg-warning me-2">Primary Representative</span>
                                <?php endif; ?>
                                Representative Details
                            </p>
                        </div>
                        <div class="d-flex gap-2 mt-2 mt-md-0 flex-wrap">
                            <a href="edit-representative.php?id=<?php echo $rep_id; ?>" class="btn btn-light">
                                <i class="fas fa-edit me-2"></i>Edit
                            </a>
                            <a href="delete-representative.php?id=<?php echo $rep_id; ?>" class="btn btn-light text-danger">
                                <i class="fas fa-trash me-2"></i>Delete
                            </a>
                            <a href="representatives.php" class="btn btn-light">
                                <i class="fas fa-arrow-left me-2"></i>Back to List
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Representative Information -->
                <div class="info-card">
                    <h5><i class="fas fa-user me-2"></i>Representative Information</h5>
                    <div class="info-row">
                        <div class="info-label">Name</div>
                        <div class="info-value"><?php echo htmlspecialchars($rep['name']); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Role</div>
                        <div class="info-value">
                            <span class="badge bg-info"><?php echo htmlspecialchars($rep['role']); ?></span>
                        </div>
                    </div>
                    <?php if ($rep['phone']): ?>
                    <div class="info-row">
                        <div class="info-label">Phone</div>
                        <div class="info-value">
                            <a href="tel:<?php echo htmlspecialchars($rep['phone']); ?>" class="text-decoration-none">
                                <i class="fas fa-phone me-2"></i><?php echo htmlspecialchars($rep['phone']); ?>
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($rep['email']): ?>
                    <div class="info-row">
                        <div class="info-label">Email</div>
                        <div class="info-value">
                            <a href="mailto:<?php echo htmlspecialchars($rep['email']); ?>" class="text-decoration-none">
                                <i class="fas fa-envelope me-2"></i><?php echo htmlspecialchars($rep['email']); ?>
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="info-row">
                        <div class="info-label">Status</div>
                        <div class="info-value">
                            <?php if ($rep['is_active']): ?>
                                <span class="badge bg-success">Active</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Inactive</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($rep['is_primary']): ?>
                    <div class="info-row">
                        <div class="info-label">Primary Representative</div>
                        <div class="info-value">
                            <span class="badge bg-warning">Yes</span>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($rep['notes']): ?>
                    <div class="info-row">
                        <div class="info-label">Notes</div>
                        <div class="info-value"><?php echo nl2br(htmlspecialchars($rep['notes'])); ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="info-row">
                        <div class="info-label">Created</div>
                        <div class="info-value"><?php echo date('F j, Y g:i A', strtotime($rep['created_at'])); ?></div>
                    </div>
                    <?php if ($rep['updated_at'] && $rep['updated_at'] !== $rep['created_at']): ?>
                    <div class="info-row">
                        <div class="info-label">Last Updated</div>
                        <div class="info-value"><?php echo date('F j, Y g:i A', strtotime($rep['updated_at'])); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Church Information -->
                <div class="info-card">
                    <h5><i class="fas fa-church me-2"></i>Church Information</h5>
                    <div class="info-row">
                        <div class="info-label">Church Name</div>
                        <div class="info-value">
                            <a href="view-church.php?id=<?php echo $rep['church_id']; ?>" class="text-decoration-none">
                                <?php echo htmlspecialchars($rep['church_name']); ?>
                            </a>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">City</div>
                        <div class="info-value">
                            <span class="badge bg-info"><?php echo htmlspecialchars($rep['church_city']); ?></span>
                        </div>
                    </div>
                    <?php if ($rep['church_address']): ?>
                    <div class="info-row">
                        <div class="info-label">Address</div>
                        <div class="info-value"><?php echo htmlspecialchars($rep['church_address']); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($rep['church_phone']): ?>
                    <div class="info-row">
                        <div class="info-label">Church Phone</div>
                        <div class="info-value">
                            <a href="tel:<?php echo htmlspecialchars($rep['church_phone']); ?>" class="text-decoration-none">
                                <?php echo htmlspecialchars($rep['church_phone']); ?>
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Assigned Donors - Smart Accordion -->
                <div class="info-card">
                    <div class="accordion" id="donorsAccordion">
                        <div class="accordion-item border-0">
                            <h2 class="accordion-header" id="donorsHeading">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" 
                                        data-bs-target="#donorsCollapse" aria-expanded="false" aria-controls="donorsCollapse">
                                    <h5 class="mb-0">
                                        <i class="fas fa-users me-2"></i>Assigned Donors
                                        <span class="badge bg-primary ms-2"><?php echo $donors_count; ?></span>
                                    </h5>
                                </button>
                            </h2>
                            <div id="donorsCollapse" class="accordion-collapse collapse" aria-labelledby="donorsHeading" 
                                 data-bs-parent="#donorsAccordion">
                                <div class="accordion-body p-0">
                                    <?php if (empty($donors)): ?>
                                        <div class="p-4 text-center text-muted">
                                            <i class="fas fa-users fa-2x mb-3 opacity-25"></i>
                                            <p>No donors assigned to this church yet.</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="p-3">
                                            <p class="text-muted small mb-3">
                                                <i class="fas fa-info-circle me-1"></i>
                                                Showing donors from <strong><?php echo htmlspecialchars($rep['church_name']); ?></strong>
                                                <?php if ($donors_count > 50): ?>
                                                    (showing first 50 of <?php echo $donors_count; ?>)
                                                <?php endif; ?>
                                            </p>
                                            <?php foreach ($donors as $donor): ?>
                                            <div class="donor-item">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div class="flex-grow-1">
                                                        <h6 class="mb-1">
                                                            <a href="../donor-management/view-donor.php?id=<?php echo $donor['id']; ?>" 
                                                               class="text-decoration-none">
                                                                <?php echo htmlspecialchars($donor['name']); ?>
                                                            </a>
                                                        </h6>
                                                        <p class="mb-1 small text-muted">
                                                            <i class="fas fa-phone me-1"></i>
                                                            <a href="tel:<?php echo htmlspecialchars($donor['phone']); ?>" class="text-decoration-none">
                                                                <?php echo htmlspecialchars($donor['phone']); ?>
                                                            </a>
                                                        </p>
                                                        <div class="donor-stats">
                                                            <span class="stat-badge bg-primary text-white">
                                                                <i class="fas fa-pound-sign me-1"></i>Pledged: £<?php echo number_format((float)$donor['total_pledged'], 2); ?>
                                                            </span>
                                                            <span class="stat-badge bg-success text-white">
                                                                <i class="fas fa-check me-1"></i>Paid: £<?php echo number_format((float)$donor['total_paid'], 2); ?>
                                                            </span>
                                                            <span class="stat-badge bg-<?php echo (float)$donor['balance'] > 0 ? 'warning' : 'secondary'; ?> text-white">
                                                                <i class="fas fa-balance-scale me-1"></i>Balance: £<?php echo number_format((float)$donor['balance'], 2); ?>
                                                            </span>
                                                            <span class="stat-badge bg-info text-white">
                                                                <?php echo ucfirst(str_replace('_', ' ', $donor['payment_status'])); ?>
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                            <?php if ($donors_count > 50): ?>
                                            <div class="text-center mt-3">
                                                <a href="../donor-management/donors.php?church_id=<?php echo $rep['church_id']; ?>" 
                                                   class="btn btn-outline-primary btn-sm">
                                                    View All <?php echo $donors_count; ?> Donors
                                                </a>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Other Representatives from Same Church -->
                <?php if (!empty($other_reps)): ?>
                <div class="info-card">
                    <h5><i class="fas fa-user-friends me-2"></i>Other Representatives</h5>
                    <p class="text-muted small mb-3">Other representatives from the same church:</p>
                    <div class="list-group">
                        <?php foreach ($other_reps as $other_rep): ?>
                        <a href="view-representative.php?id=<?php echo $other_rep['id']; ?>" 
                           class="list-group-item list-group-item-action">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <strong><?php echo htmlspecialchars($other_rep['name']); ?></strong>
                                    <?php if ($other_rep['is_primary']): ?>
                                        <span class="badge bg-warning ms-2">Primary</span>
                                    <?php endif; ?>
                                    <br>
                                    <small class="text-muted"><?php echo htmlspecialchars($other_rep['role']); ?></small>
                                </div>
                                <i class="fas fa-chevron-right text-muted"></i>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
</body>
</html>

