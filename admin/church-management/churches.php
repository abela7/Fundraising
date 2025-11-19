<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

$db = db();
$page_title = 'All Churches';

// Handle success/error messages
$success_message = $_GET['success'] ?? null;
$error_message = $_GET['error'] ?? null;

// Search and filter
$search = $_GET['search'] ?? '';
$city_filter = $_GET['city'] ?? '';
$status_filter = $_GET['status'] ?? 'all';

// Build query
$where_conditions = [];
$params = [];
$types = '';

if (!empty($search)) {
    $where_conditions[] = "(c.name LIKE ? OR c.city LIKE ? OR c.address LIKE ? OR c.phone LIKE ?)";
    $search_param = "%{$search}%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'ssss';
}

if (!empty($city_filter)) {
    $where_conditions[] = "c.city = ?";
    $params[] = $city_filter;
    $types .= 's';
}

if ($status_filter === 'active') {
    $where_conditions[] = "c.is_active = 1";
} elseif ($status_filter === 'inactive') {
    $where_conditions[] = "c.is_active = 0";
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Fetch churches
try {
    $query = "
        SELECT 
            c.id,
            c.name,
            c.city,
            c.address,
            c.phone,
            c.is_active,
            c.created_at,
            COUNT(DISTINCT cr.id) as rep_count,
            COUNT(DISTINCT d.id) as donor_count
        FROM churches c
        LEFT JOIN church_representatives cr ON c.id = cr.church_id AND cr.is_active = 1
        LEFT JOIN donors d ON c.id = d.church_id
        {$where_clause}
        GROUP BY c.id
        ORDER BY c.city ASC, c.name ASC
    ";
    
    $stmt = $db->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $churches = $result->fetch_all(MYSQLI_ASSOC);
    
    // Get unique cities for filter
    $cities_query = $db->query("SELECT DISTINCT city FROM churches WHERE city IS NOT NULL AND city != '' ORDER BY city ASC");
    $cities = [];
    while ($row = $cities_query->fetch_assoc()) {
        $cities[] = $row['city'];
    }
    
} catch (Exception $e) {
    $error_message = "Error loading churches: " . $e->getMessage();
    $churches = [];
    $cities = [];
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
        }
        
        .page-header {
            background: linear-gradient(135deg, var(--church-primary) 0%, #084767 100%);
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
        
        .church-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            transition: all 0.2s ease;
        }
        
        .church-card:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        
        .church-name {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--church-primary);
            margin-bottom: 0.5rem;
        }
        
        .church-name-link {
            color: var(--church-primary);
            transition: color 0.2s ease;
        }
        
        .church-name-link:hover {
            color: #084767;
            text-decoration: underline !important;
        }
        
        .churches-table tbody tr {
            cursor: pointer;
            transition: background-color 0.2s ease;
        }
        
        .churches-table tbody tr:hover {
            background: #f1f5f9 !important;
        }
        
        .church-meta {
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
            color: var(--church-primary);
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
            
            .church-card {
                padding: 1rem;
            }
            
            .church-name {
                font-size: 1.125rem;
            }
            
            .church-meta {
                flex-direction: column;
                gap: 0.5rem;
            }
            
        }
        
        /* Table view for desktop */
        @media (min-width: 992px) {
            .churches-table {
                display: table;
                width: 100%;
                background: white;
                border-radius: 12px;
                overflow: hidden;
                box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            }
            
            .churches-table thead {
                background: #f8fafc;
            }
            
            .churches-table th {
                padding: 1rem;
                font-weight: 600;
                color: #475569;
                border-bottom: 2px solid #e2e8f0;
            }
            
            .churches-table td {
                padding: 1rem;
                border-bottom: 1px solid #e2e8f0;
                vertical-align: middle;
            }
            
            .churches-table tbody tr:hover {
                background: #f8fafc;
            }
            
            .churches-table tbody tr:last-child td {
                border-bottom: none;
            }
            
            .church-card {
                display: none;
            }
        }
        
        @media (max-width: 991px) {
            .churches-table {
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
                            <h1 class="mb-2"><i class="fas fa-church me-2"></i>All Churches</h1>
                            <p class="mb-0 opacity-75">Manage churches and their representatives</p>
                        </div>
                        <a href="add-church.php" class="btn btn-light btn-lg mt-2 mt-md-0">
                            <i class="fas fa-plus-circle me-2"></i>Add New Church
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
                                       placeholder="Search by name, city, address..." 
                                       value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">City</label>
                            <select name="city" class="form-select">
                                <option value="">All Cities</option>
                                <?php foreach ($cities as $city): ?>
                                <option value="<?php echo htmlspecialchars($city); ?>" 
                                        <?php echo $city_filter === $city ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($city); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-filter me-2"></i>Filter
                            </button>
                        </div>
                        <?php if (!empty($search) || !empty($city_filter) || $status_filter !== 'all'): ?>
                        <div class="col-12">
                            <a href="churches.php" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-times me-2"></i>Clear Filters
                            </a>
                        </div>
                        <?php endif; ?>
                    </form>
                </div>
                
                <!-- Churches List -->
                <?php if (empty($churches)): ?>
                    <div class="empty-state">
                        <i class="fas fa-church"></i>
                        <h3>No Churches Found</h3>
                        <p class="text-muted"><?php echo !empty($search) || !empty($city_filter) || $status_filter !== 'all' ? 'Try adjusting your filters.' : 'Get started by adding your first church.'; ?></p>
                        <?php if (empty($search) && empty($city_filter) && $status_filter === 'all'): ?>
                        <a href="add-church.php" class="btn btn-primary mt-3">
                            <i class="fas fa-plus-circle me-2"></i>Add New Church
                        </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    
                    <!-- Desktop Table View -->
                    <table class="churches-table">
                        <thead>
                            <tr>
                                <th>Church Name</th>
                                <th>City</th>
                                <th>Donors</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($churches as $church): ?>
                            <tr style="cursor: pointer;" onclick="window.location.href='view-church.php?id=<?php echo $church['id']; ?>'">
                                <td>
                                    <a href="view-church.php?id=<?php echo $church['id']; ?>" 
                                       class="text-decoration-none church-name-link">
                                        <?php echo htmlspecialchars($church['name']); ?>
                                    </a>
                                </td>
                                <td>
                                    <span class="badge bg-info badge-custom"><?php echo htmlspecialchars($church['city']); ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-success badge-custom">
                                        <i class="fas fa-users me-1"></i><?php echo $church['donor_count']; ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <!-- Mobile Card View -->
                    <?php foreach ($churches as $church): ?>
                    <div class="church-card" onclick="window.location.href='view-church.php?id=<?php echo $church['id']; ?>'" style="cursor: pointer;">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="flex-grow-1">
                                <div class="church-name">
                                    <a href="view-church.php?id=<?php echo $church['id']; ?>" 
                                       class="text-decoration-none church-name-link">
                                        <?php echo htmlspecialchars($church['name']); ?>
                                    </a>
                                </div>
                                <div class="church-meta mt-2">
                                    <div class="meta-item">
                                        <i class="fas fa-map-marker-alt"></i>
                                        <span><?php echo htmlspecialchars($church['city']); ?></span>
                                    </div>
                                    <div class="meta-item">
                                        <i class="fas fa-users"></i>
                                        <span><?php echo $church['donor_count']; ?> Donor<?php echo $church['donor_count'] != 1 ? 's' : ''; ?></span>
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

