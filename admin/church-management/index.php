<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

$db = db();
$page_title = 'Church Management';

// Fetch statistics
try {
    // Total churches
    $total_churches_query = $db->query("SELECT COUNT(*) as count FROM churches WHERE is_active = 1");
    $total_churches = $total_churches_query->fetch_assoc()['count'] ?? 0;
    
    // Total representatives
    $total_reps_query = $db->query("SELECT COUNT(*) as count FROM church_representatives WHERE is_active = 1");
    $total_reps = $total_reps_query->fetch_assoc()['count'] ?? 0;
    
    // Churches by city
    $cities_query = $db->query("SELECT COUNT(DISTINCT city) as count FROM churches WHERE is_active = 1");
    $total_cities = $cities_query->fetch_assoc()['count'] ?? 0;
    
    // Donors assigned to churches
    $assigned_donors_query = $db->query("SELECT COUNT(*) as count FROM donors WHERE church_id IS NOT NULL");
    $assigned_donors = $assigned_donors_query->fetch_assoc()['count'] ?? 0;
    
    // Get all churches with representative count
    $churches_query = "
        SELECT 
            c.id,
            c.name,
            c.city,
            c.address,
            c.phone,
            c.is_active,
            COUNT(cr.id) as rep_count,
            (SELECT COUNT(*) FROM donors WHERE church_id = c.id) as donor_count
        FROM churches c
        LEFT JOIN church_representatives cr ON c.id = cr.church_id AND cr.is_active = 1
        WHERE c.is_active = 1
        GROUP BY c.id
        ORDER BY c.city, c.name
    ";
    $churches_result = $db->query($churches_query);
    
} catch (Exception $e) {
    $error_message = "Error loading data: " . $e->getMessage();
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
            --church-secondary: #d32f2f;
            --church-success: #2e7d32;
            --church-info: #0288d1;
        }
        
        /* Dashboard specific styles */
        .main-content {
            padding: 2rem 1.5rem;
        }
        
        .dashboard-header {
            background: linear-gradient(135deg, var(--church-primary) 0%, #084767 100%);
            color: white;
            padding: 2rem 1rem;
            margin-bottom: 2rem;
            border-radius: 12px;
        }
        
        .dashboard-header h1 {
            font-size: 1.75rem;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border-left: 4px solid var(--church-primary);
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .stat-card.churches { border-left-color: var(--church-primary); }
        .stat-card.reps { border-left-color: var(--church-success); }
        .stat-card.cities { border-left-color: var(--church-info); }
        .stat-card.donors { border-left-color: var(--church-secondary); }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--church-primary);
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            color: #64748b;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }
        
        .stat-card.churches .stat-icon { background: rgba(10, 98, 134, 0.1); color: var(--church-primary); }
        .stat-card.reps .stat-icon { background: rgba(46, 125, 50, 0.1); color: var(--church-success); }
        .stat-card.cities .stat-icon { background: rgba(2, 136, 209, 0.1); color: var(--church-info); }
        .stat-card.donors .stat-icon { background: rgba(211, 47, 47, 0.1); color: var(--church-secondary); }
        
        .church-table {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        
        .church-table thead {
            background: var(--church-primary);
            color: white;
        }
        
        .church-table th {
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.5px;
            padding: 1rem;
            border: none;
        }
        
        .church-table td {
            padding: 1rem;
            vertical-align: middle;
        }
        
        .church-table tbody tr {
            border-bottom: 1px solid #e2e8f0;
            transition: all 0.2s ease;
        }
        
        .church-table tbody tr:hover {
            background: #f8fafc;
        }
        
        .church-name {
            font-weight: 600;
            color: var(--church-primary);
            margin-bottom: 0.25rem;
        }
        
        .church-address {
            font-size: 0.875rem;
            color: #64748b;
        }
        
        .avatar-sm {
            width: 40px;
            height: 40px;
            font-size: 1rem;
        }
        
        .list-group-item {
            padding: 1rem 0;
        }
        
        .list-group-item:last-child {
            border-bottom: none !important;
        }
        
        .city-badge {
            background: var(--church-info);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .count-badge {
            background: #f1f5f9;
            color: #334155;
            padding: 0.25rem 0.6rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .count-badge i {
            margin-right: 0.25rem;
        }
        
        .action-btn {
            padding: 0.375rem 0.75rem;
            border-radius: 6px;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        
        .quick-action-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            text-align: center;
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }
        
        .quick-action-card:hover {
            border-color: var(--church-primary);
            transform: translateY(-4px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .quick-action-icon {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: rgba(10, 98, 134, 0.1);
            color: var(--church-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            margin: 0 auto 1rem;
        }
        
        .quick-action-title {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.5rem;
        }
        
        .quick-action-desc {
            font-size: 0.875rem;
            color: #64748b;
        }
        
        /* Mobile Responsive */
        @media (max-width: 992px) {
            .main-content {
                padding: 1.5rem 1rem;
            }
            
            .dashboard-header {
                padding: 1.5rem 1rem;
                margin-bottom: 1.5rem;
            }
            
            .dashboard-header h1 {
                font-size: 1.5rem;
            }
            
            .dashboard-header p {
                font-size: 0.875rem;
            }
            
            .dashboard-header .d-flex {
                flex-direction: column;
                align-items: flex-start !important;
            }
            
            .dashboard-header .btn {
                margin-top: 1rem;
                width: 100%;
            }
        }
        
        @media (max-width: 768px) {
            .stat-number {
                font-size: 2rem;
            }
            
            .stat-card {
                padding: 1rem;
            }
            
            .stat-icon {
                width: 50px;
                height: 50px;
                font-size: 1.25rem;
            }
            
            /* Responsive list groups */
            .list-group-item {
                padding: 0.75rem 0;
            }
            
            .avatar-sm {
                width: 35px;
                height: 35px;
                font-size: 0.875rem;
            }
            
            .quick-action-card {
                padding: 1rem;
            }
            
            .quick-action-icon {
                width: 50px;
                height: 50px;
                font-size: 1.5rem;
            }
            
            .quick-action-title {
                font-size: 0.875rem;
            }
            
            .quick-action-desc {
                font-size: 0.75rem;
            }
        }
        
        @media (max-width: 576px) {
            .dashboard-header h1 {
                font-size: 1.25rem;
            }
            
            .stat-number {
                font-size: 1.75rem;
            }
            
            .stat-label {
                font-size: 0.75rem;
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
                
                <!-- Dashboard Header -->
                <div class="dashboard-header">
                    <div class="d-flex justify-content-between align-items-center px-4">
                        <div>
                            <h1 class="mb-2"><i class="fas fa-church me-3"></i>Church Management</h1>
                            <p class="mb-0 opacity-90">Manage churches, representatives, and donor assignments</p>
                        </div>
                        <div>
                            <a href="add-church.php" class="btn btn-light btn-lg">
                                <i class="fas fa-plus me-2"></i>Add New Church
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Statistics Cards -->
                <div class="row g-4 mb-4">
                    <div class="col-md-6 col-xl-3">
                        <div class="stat-card churches">
                            <div class="stat-icon">
                                <i class="fas fa-church"></i>
                            </div>
                            <div class="stat-number"><?php echo $total_churches; ?></div>
                            <div class="stat-label">Active Churches</div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="stat-card reps">
                            <div class="stat-icon">
                                <i class="fas fa-user-tie"></i>
                            </div>
                            <div class="stat-number"><?php echo $total_reps; ?></div>
                            <div class="stat-label">Representatives</div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="stat-card cities">
                            <div class="stat-icon">
                                <i class="fas fa-map-marked-alt"></i>
                            </div>
                            <div class="stat-number"><?php echo $total_cities; ?></div>
                            <div class="stat-label">Cities Covered</div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="stat-card donors">
                            <div class="stat-icon">
                                <i class="fas fa-users"></i>
                            </div>
                            <div class="stat-number"><?php echo $assigned_donors; ?></div>
                            <div class="stat-label">Donors Assigned</div>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <a href="churches.php" class="text-decoration-none">
                            <div class="quick-action-card">
                                <div class="quick-action-icon">
                                    <i class="fas fa-list"></i>
                                </div>
                                <div class="quick-action-title">View All Churches</div>
                                <div class="quick-action-desc">Browse and manage churches</div>
                            </div>
                        </a>
                    </div>
                    <div class="col-md-3">
                        <a href="representatives.php" class="text-decoration-none">
                            <div class="quick-action-card">
                                <div class="quick-action-icon">
                                    <i class="fas fa-id-card"></i>
                                </div>
                                <div class="quick-action-title">Representatives</div>
                                <div class="quick-action-desc">Manage church representatives</div>
                            </div>
                        </a>
                    </div>
                    <div class="col-md-3">
                        <a href="assign-donors.php" class="text-decoration-none">
                            <div class="quick-action-card">
                                <div class="quick-action-icon">
                                    <i class="fas fa-link"></i>
                                </div>
                                <div class="quick-action-title">Assign Donors</div>
                                <div class="quick-action-desc">Link donors to churches</div>
                            </div>
                        </a>
                    </div>
                    <div class="col-md-3">
                        <a href="reports.php" class="text-decoration-none">
                            <div class="quick-action-card">
                                <div class="quick-action-icon">
                                    <i class="fas fa-chart-pie"></i>
                                </div>
                                <div class="quick-action-title">Reports</div>
                                <div class="quick-action-desc">Church statistics & insights</div>
                            </div>
                        </a>
                    </div>
                </div>
                
                <!-- Recent Activity / Overview -->
                <div class="row g-4">
                    <!-- Cities Breakdown -->
                    <div class="col-lg-6">
                        <div class="church-table">
                            <div class="p-4 border-bottom">
                                <h5 class="mb-0"><i class="fas fa-map-marked-alt me-2 text-info"></i>Churches by City</h5>
                            </div>
                            <div class="p-4">
                                <?php
                                $cities_breakdown = $db->query("
                                    SELECT city, COUNT(*) as count 
                                    FROM churches 
                                    WHERE is_active = 1 
                                    GROUP BY city 
                                    ORDER BY count DESC 
                                    LIMIT 10
                                ");
                                if ($cities_breakdown && $cities_breakdown->num_rows > 0):
                                ?>
                                    <div class="list-group list-group-flush">
                                        <?php while ($city = $cities_breakdown->fetch_assoc()): ?>
                                        <div class="list-group-item d-flex justify-content-between align-items-center border-0 px-0">
                                            <div>
                                                <i class="fas fa-map-marker-alt text-info me-2"></i>
                                                <strong><?php echo htmlspecialchars($city['city']); ?></strong>
                                            </div>
                                            <span class="badge bg-primary rounded-pill"><?php echo $city['count']; ?> church<?php echo $city['count'] > 1 ? 'es' : ''; ?></span>
                                        </div>
                                        <?php endwhile; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted text-center py-4">No data available</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Top Representatives -->
                    <div class="col-lg-6">
                        <div class="church-table">
                            <div class="p-4 border-bottom">
                                <h5 class="mb-0"><i class="fas fa-users me-2 text-success"></i>Recent Representatives</h5>
                            </div>
                            <div class="p-4">
                                <?php
                                $recent_reps = $db->query("
                                    SELECT cr.name, cr.role, c.name as church_name, c.city
                                    FROM church_representatives cr
                                    JOIN churches c ON cr.church_id = c.id
                                    WHERE cr.is_active = 1
                                    ORDER BY cr.created_at DESC
                                    LIMIT 8
                                ");
                                if ($recent_reps && $recent_reps->num_rows > 0):
                                ?>
                                    <div class="list-group list-group-flush">
                                        <?php while ($rep = $recent_reps->fetch_assoc()): ?>
                                        <div class="list-group-item border-0 px-0">
                                            <div class="d-flex align-items-start">
                                                <div class="flex-shrink-0">
                                                    <div class="avatar-sm bg-success bg-opacity-10 text-success rounded-circle d-flex align-items-center justify-content-center">
                                                        <i class="fas fa-user-tie"></i>
                                                    </div>
                                                </div>
                                                <div class="flex-grow-1 ms-3">
                                                    <h6 class="mb-1"><?php echo htmlspecialchars($rep['name']); ?></h6>
                                                    <p class="mb-0 small text-muted">
                                                        <span class="badge bg-light text-dark"><?php echo htmlspecialchars($rep['role']); ?></span>
                                                        • <?php echo htmlspecialchars($rep['church_name']); ?>
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endwhile; ?>
                                    </div>
                                    <div class="text-center mt-3">
                                        <a href="representatives.php" class="btn btn-outline-primary btn-sm">View All Representatives</a>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted text-center py-4">No representatives found</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
</body>
</html>

