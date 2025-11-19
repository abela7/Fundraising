<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

$db = db();
$page_title = 'All Representatives';

// Handle success/error messages
$success_message = $_GET['success'] ?? null;
$error_message = $_GET['error'] ?? null;

// Search and filter
$search = $_GET['search'] ?? '';
$church_filter = $_GET['church'] ?? '';
$role_filter = $_GET['role'] ?? '';
$status_filter = $_GET['status'] ?? 'all';

// Build query
$where_conditions = [];
$params = [];
$types = '';

if (!empty($search)) {
    $where_conditions[] = "(cr.name LIKE ? OR cr.role LIKE ? OR cr.phone LIKE ? OR cr.email LIKE ? OR c.name LIKE ?)";
    $search_param = "%{$search}%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'sssss';
}

if (!empty($church_filter)) {
    $where_conditions[] = "cr.church_id = ?";
    $params[] = $church_filter;
    $types .= 'i';
}

if (!empty($role_filter)) {
    $where_conditions[] = "cr.role = ?";
    $params[] = $role_filter;
    $types .= 's';
}

if ($status_filter === 'active') {
    $where_conditions[] = "cr.is_active = 1";
} elseif ($status_filter === 'inactive') {
    $where_conditions[] = "cr.is_active = 0";
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Fetch representatives
try {
    // Check if representative_id column exists
    $check_column = $db->query("SHOW COLUMNS FROM donors LIKE 'representative_id'");
    $has_rep_column = $check_column && $check_column->num_rows > 0;
    
    if ($has_rep_column) {
        // Use representative_id if column exists
        $query = "
            SELECT 
                cr.id,
                cr.name,
                cr.role,
                cr.phone,
                cr.email,
                cr.is_primary,
                cr.is_active,
                cr.church_id,
                c.name as church_name,
                c.city as church_city,
                COUNT(DISTINCT CASE WHEN d.representative_id = cr.id THEN d.id END) as donor_count
            FROM church_representatives cr
            INNER JOIN churches c ON cr.church_id = c.id
            LEFT JOIN donors d ON d.representative_id = cr.id
            {$where_clause}
            GROUP BY cr.id
            ORDER BY c.city ASC, c.name ASC, cr.is_primary DESC, cr.name ASC
        ";
    } else {
        // Fallback to church_id if column doesn't exist yet
        $query = "
            SELECT 
                cr.id,
                cr.name,
                cr.role,
                cr.phone,
                cr.email,
                cr.is_primary,
                cr.is_active,
                cr.church_id,
                c.name as church_name,
                c.city as church_city,
                COUNT(DISTINCT d.id) as donor_count
            FROM church_representatives cr
            INNER JOIN churches c ON cr.church_id = c.id
            LEFT JOIN donors d ON c.id = d.church_id
            {$where_clause}
            GROUP BY cr.id
            ORDER BY c.city ASC, c.name ASC, cr.is_primary DESC, cr.name ASC
        ";
    }
    
    $stmt = $db->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $representatives = $result->fetch_all(MYSQLI_ASSOC);
    
    // Get churches for filter
    $churches_query = $db->query("SELECT id, name, city FROM churches WHERE is_active = 1 ORDER BY city, name");
    $churches = [];
    while ($row = $churches_query->fetch_assoc()) {
        $churches[] = $row;
    }
    
    // Get unique roles for filter
    $roles_query = $db->query("SELECT DISTINCT role FROM church_representatives WHERE role IS NOT NULL AND role != '' ORDER BY role");
    $roles = [];
    while ($row = $roles_query->fetch_assoc()) {
        $roles[] = $row['role'];
    }
    
} catch (Exception $e) {
    $error_message = "Error loading representatives: " . $e->getMessage();
    $representatives = [];
    $churches = [];
    $roles = [];
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
        }
        
        .page-header {
            background: linear-gradient(135deg, var(--rep-primary) 0%, #084767 100%);
            color: white;
            padding: 2rem 1.5rem;
            margin-bottom: 2rem;
            border-radius: 12px;
        }
        
        .filter-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .rep-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            transition: all 0.2s ease;
        }
        
        .rep-card:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        
        .rep-name {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--rep-primary);
            margin-bottom: 0.5rem;
        }
        
        .rep-name-link {
            color: var(--rep-primary);
            transition: color 0.2s ease;
        }
        
        .rep-name-link:hover {
            color: #084767;
            text-decoration: underline !important;
        }
        
        .rep-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-top: 0.75rem;
        }
        
        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #64748b;
            font-size: 0.875rem;
        }
        
        .meta-item i {
            color: var(--rep-primary);
        }
        
        .badge-custom {
            padding: 0.375rem 0.75rem;
            border-radius: 6px;
            font-weight: 500;
            font-size: 0.875rem;
        }
        
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            background: white;
            border-radius: 12px;
        }
        
        .empty-state i {
            font-size: 4rem;
            color: #cbd5e1;
            margin-bottom: 1rem;
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .page-header {
                padding: 1.5rem 1rem;
            }
            
            .page-header h1 {
                font-size: 1.5rem;
            }
            
            .filter-card {
                padding: 1rem;
            }
            
            .rep-card {
                padding: 1rem;
            }
            
            .rep-name {
                font-size: 1.125rem;
            }
            
            .rep-meta {
                flex-direction: column;
                gap: 0.5rem;
            }
        }
        
        /* Table view for desktop */
        @media (min-width: 992px) {
            .reps-table {
                display: table;
                width: 100%;
                background: white;
                border-radius: 12px;
                overflow: hidden;
                box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            }
            
            .reps-table thead {
                background: #f8fafc;
            }
            
            .reps-table th {
                padding: 1rem;
                font-weight: 600;
                color: #475569;
                border-bottom: 2px solid #e2e8f0;
            }
            
            .reps-table td {
                padding: 1rem;
                border-bottom: 1px solid #e2e8f0;
                vertical-align: middle;
            }
            
            .reps-table tbody tr {
                cursor: pointer;
                transition: background-color 0.2s ease;
            }
            
            .reps-table tbody tr:hover {
                background: #f1f5f9 !important;
            }
            
            .reps-table tbody tr:last-child td {
                border-bottom: none;
            }
            
            .rep-card {
                display: none;
            }
        }
        
        @media (max-width: 991px) {
            .reps-table {
                display: none;
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
                            <h1 class="mb-2"><i class="fas fa-user-tie me-2"></i>All Representatives</h1>
                            <p class="mb-0 opacity-75">Manage church representatives</p>
                        </div>
                        <a href="add-representative.php" class="btn btn-light btn-lg mt-2 mt-md-0">
                            <i class="fas fa-plus-circle me-2"></i>Add New Representative
                        </a>
                    </div>
                </div>
                
                <!-- Success/Error Messages -->
                <?php if ($success_message): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <?php if ($error_message): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <!-- Filters -->
                <div class="filter-card">
                    <form method="GET" action="" class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Search</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-search"></i></span>
                                <input type="text" name="search" class="form-control" 
                                       placeholder="Search by name, role, phone..." 
                                       value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Church</label>
                            <select name="church" class="form-select">
                                <option value="">All Churches</option>
                                <?php foreach ($churches as $church): ?>
                                <option value="<?php echo $church['id']; ?>" 
                                        <?php echo $church_filter == $church['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($church['city'] . ' - ' . $church['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Role</label>
                            <select name="role" class="form-select">
                                <option value="">All Roles</option>
                                <?php foreach ($roles as $role): ?>
                                <option value="<?php echo htmlspecialchars($role); ?>" 
                                        <?php echo $role_filter === $role ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($role); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-1 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-filter"></i>
                            </button>
                        </div>
                        <?php if (!empty($search) || !empty($church_filter) || !empty($role_filter) || $status_filter !== 'all'): ?>
                        <div class="col-12">
                            <a href="representatives.php" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-times me-2"></i>Clear Filters
                            </a>
                        </div>
                        <?php endif; ?>
                    </form>
                </div>
                
                <!-- Representatives List -->
                <?php if (empty($representatives)): ?>
                    <div class="empty-state">
                        <i class="fas fa-user-tie"></i>
                        <h3>No Representatives Found</h3>
                        <p class="text-muted"><?php echo !empty($search) || !empty($church_filter) || !empty($role_filter) || $status_filter !== 'all' ? 'Try adjusting your filters.' : 'Get started by adding your first representative.'; ?></p>
                        <?php if (empty($search) && empty($church_filter) && empty($role_filter) && $status_filter === 'all'): ?>
                        <a href="add-representative.php" class="btn btn-primary mt-3">
                            <i class="fas fa-plus-circle me-2"></i>Add New Representative
                        </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    
                    <!-- Desktop Table View -->
                    <table class="reps-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Role</th>
                                <th>Church</th>
                                <th>Donors</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($representatives as $rep): ?>
                            <tr onclick="window.location.href='view-representative.php?id=<?php echo $rep['id']; ?>'">
                                <td>
                                    <a href="view-representative.php?id=<?php echo $rep['id']; ?>" 
                                       class="text-decoration-none rep-name-link">
                                        <?php echo htmlspecialchars($rep['name']); ?>
                                        <?php if ($rep['is_primary']): ?>
                                            <span class="badge bg-success ms-2">Primary</span>
                                        <?php endif; ?>
                                    </a>
                                </td>
                                <td>
                                    <span class="badge bg-info badge-custom"><?php echo htmlspecialchars($rep['role']); ?></span>
                                </td>
                                <td>
                                    <div>
                                        <strong><?php echo htmlspecialchars($rep['church_name']); ?></strong><br>
                                        <small class="text-muted"><?php echo htmlspecialchars($rep['church_city']); ?></small>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-success badge-custom">
                                        <i class="fas fa-users me-1"></i><?php echo $rep['donor_count']; ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <!-- Mobile Card View -->
                    <?php foreach ($representatives as $rep): ?>
                    <div class="rep-card" onclick="window.location.href='view-representative.php?id=<?php echo $rep['id']; ?>'" style="cursor: pointer;">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="flex-grow-1">
                                <div class="rep-name">
                                    <a href="view-representative.php?id=<?php echo $rep['id']; ?>" 
                                       class="text-decoration-none rep-name-link">
                                        <?php echo htmlspecialchars($rep['name']); ?>
                                    </a>
                                    <?php if ($rep['is_primary']): ?>
                                        <span class="badge bg-success ms-2">Primary</span>
                                    <?php endif; ?>
                                </div>
                                <div class="rep-meta mt-2">
                                    <div class="meta-item">
                                        <i class="fas fa-briefcase"></i>
                                        <span><?php echo htmlspecialchars($rep['role']); ?></span>
                                    </div>
                                    <div class="meta-item">
                                        <i class="fas fa-church"></i>
                                        <span><?php echo htmlspecialchars($rep['church_name']); ?></span>
                                    </div>
                                    <div class="meta-item">
                                        <i class="fas fa-users"></i>
                                        <span><?php echo $rep['donor_count']; ?> Donor<?php echo $rep['donor_count'] != 1 ? 's' : ''; ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                <?php endif; ?>
                
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
</body>
</html>

