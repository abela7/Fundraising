<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_admin();

$db = db();
$page_title = 'Assign Donors to Agents';

// Get filter parameters
$filter_search = $_GET['search'] ?? '';
$filter_min_pledge = isset($_GET['min_pledge']) && $_GET['min_pledge'] !== '' ? (float)$_GET['min_pledge'] : null;
$filter_max_pledge = isset($_GET['max_pledge']) && $_GET['max_pledge'] !== '' ? (float)$_GET['max_pledge'] : null;
$filter_min_balance = isset($_GET['min_balance']) && $_GET['min_balance'] !== '' ? (float)$_GET['min_balance'] : null;
$filter_max_balance = isset($_GET['max_balance']) && $_GET['max_balance'] !== '' ? (float)$_GET['max_balance'] : null;
$filter_registrar = isset($_GET['registrar']) && $_GET['registrar'] !== '' ? (int)$_GET['registrar'] : null;
$filter_assignment = $_GET['assignment'] ?? 'all'; // all, assigned, unassigned
$filter_donation_type = $_GET['donation_type'] ?? 'all'; // all, pledge, payment

// Get sorting parameters
$sort_by = $_GET['sort_by'] ?? 'name'; // id, name, pledge, balance
$sort_order = $_GET['sort_order'] ?? 'asc'; // asc, desc

// Validate sort parameters
$valid_sort_columns = ['id', 'name', 'pledge', 'balance'];
if (!in_array($sort_by, $valid_sort_columns)) {
    $sort_by = 'name';
}
if (!in_array($sort_order, ['asc', 'desc'])) {
    $sort_order = 'asc';
}

// Map sort_by to actual column names
$sort_column_map = [
    'id' => 'd.id',
    'name' => 'd.name',
    'pledge' => 'total_pledged',
    'balance' => 'balance'
];
$order_by_clause = $sort_column_map[$sort_by] . ' ' . strtoupper($sort_order);

// Handle bulk assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $donor_ids = $_POST['donor_ids'] ?? [];
    $action = $_POST['bulk_action'];
    $agent_id = isset($_POST['bulk_agent_id']) ? (int)$_POST['bulk_agent_id'] : 0;
    
    if (!empty($donor_ids) && is_array($donor_ids)) {
        try {
            $success_count = 0;
            foreach ($donor_ids as $donor_id) {
                $donor_id = (int)$donor_id;
                if ($donor_id > 0) {
                    if ($action === 'assign' && $agent_id > 0) {
                        $stmt = $db->prepare("UPDATE donors SET agent_id = ? WHERE id = ?");
                        $stmt->bind_param('ii', $agent_id, $donor_id);
                        $stmt->execute();
                        $success_count++;
                    } elseif ($action === 'unassign') {
                        $stmt = $db->prepare("UPDATE donors SET agent_id = NULL WHERE id = ?");
                        $stmt->bind_param('i', $donor_id);
                        $stmt->execute();
                        $success_count++;
                    }
                }
            }
            header("Location: assign-donors.php");
            exit;
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
}
    }
}

// Handle single assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign']) && !isset($_POST['bulk_action'])) {
    $donor_id = (int)$_POST['donor_id'];
    $agent_id = (int)$_POST['agent_id'];
    
    if ($donor_id > 0) {
        try {
            if ($agent_id > 0) {
                $stmt = $db->prepare("UPDATE donors SET agent_id = ? WHERE id = ?");
                $stmt->bind_param('ii', $agent_id, $donor_id);
                $stmt->execute();
            } else {
                $stmt = $db->prepare("UPDATE donors SET agent_id = NULL WHERE id = ?");
                $stmt->bind_param('i', $donor_id);
                $stmt->execute();
            }
            header("Location: assign-donors.php");
            exit;
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
        }
    }
}

// Function to generate sort URL
function getSortUrl($column, $current_sort_by, $current_sort_order) {
    $params = $_GET;
    $params['sort_by'] = $column;
    
    // Toggle sort order if clicking on the same column
    if ($current_sort_by === $column) {
        $params['sort_order'] = $current_sort_order === 'asc' ? 'desc' : 'asc';
    } else {
        $params['sort_order'] = 'asc';
    }
    
    return 'assign-donors.php?' . http_build_query($params);
}

// Function to get sort icon
function getSortIcon($column, $current_sort_by, $current_sort_order) {
    if ($current_sort_by !== $column) {
        return '<i class="fas fa-sort text-muted ms-1" style="opacity: 0.3;"></i>';
    }
    
    if ($current_sort_order === 'asc') {
        return '<i class="fas fa-sort-up text-primary ms-1"></i>';
    } else {
        return '<i class="fas fa-sort-down text-primary ms-1"></i>';
    }
}

// Initialize variables
$agents = [];
$donors_by_agent = [];
$unassigned_donors = [];

try {
    // Get agents (active registrars only)
    $agents_result = $db->query("SELECT id, name FROM users WHERE role = 'registrar' AND active = 1 ORDER BY name");
    if ($agents_result) {
        while ($agent = $agents_result->fetch_assoc()) {
            $agents[] = $agent;
        }
    }
    
    // Get all registrars for filter dropdown (active registrars only)
    $registrars = [];
    $registrars_result = $db->query("SELECT id, name FROM users WHERE role = 'registrar' AND active = 1 ORDER BY name");
    if ($registrars_result) {
        while ($registrar = $registrars_result->fetch_assoc()) {
            $registrars[] = $registrar;
        }
    }

    // Build WHERE clause for filters
    $where_conditions = [];
$params = [];
    $types = '';

    if (!empty($filter_search)) {
        $where_conditions[] = "(d.name LIKE ? OR d.phone LIKE ?)";
        $search_param = "%{$filter_search}%";
    $params[] = $search_param;
    $params[] = $search_param;
        $types .= 'ss';
}

    if ($filter_min_pledge !== null) {
        $where_conditions[] = "COALESCE(d.total_pledged, 0) >= ?";
        $params[] = $filter_min_pledge;
        $types .= 'd';
}

    if ($filter_max_pledge !== null) {
        $where_conditions[] = "COALESCE(d.total_pledged, 0) <= ?";
        $params[] = $filter_max_pledge;
        $types .= 'd';
}

    if ($filter_min_balance !== null) {
        $where_conditions[] = "(COALESCE(d.total_pledged, 0) - COALESCE(d.total_paid, 0)) >= ?";
        $params[] = $filter_min_balance;
        $types .= 'd';
    }
    
    if ($filter_max_balance !== null) {
        $where_conditions[] = "(COALESCE(d.total_pledged, 0) - COALESCE(d.total_paid, 0)) <= ?";
        $params[] = $filter_max_balance;
        $types .= 'd';
}

    if ($filter_registrar !== null) {
        $where_conditions[] = "(d.id IN (
            SELECT DISTINCT donor_id FROM pledges WHERE created_by_user_id = ? AND donor_id IS NOT NULL
            UNION
            SELECT DISTINCT donor_id FROM payments WHERE received_by_user_id = ? AND donor_id IS NOT NULL
        ))";
        $params[] = $filter_registrar;
        $params[] = $filter_registrar;
        $types .= 'ii';
}

    if ($filter_donation_type === 'pledge') {
        $where_conditions[] = "d.id IN (SELECT DISTINCT donor_id FROM pledges WHERE donor_id IS NOT NULL)";
    } elseif ($filter_donation_type === 'payment') {
        $where_conditions[] = "d.id IN (SELECT DISTINCT donor_id FROM payments WHERE donor_id IS NOT NULL)";
    }
    
    if ($filter_assignment === 'assigned') {
        $where_conditions[] = "d.agent_id IS NOT NULL";
    } elseif ($filter_assignment === 'unassigned') {
        $where_conditions[] = "d.agent_id IS NULL";
}

    // Get donors by agent (with filters)
    foreach ($agents as $agent) {
        $agent_where = array_merge(["d.agent_id = ?"], $where_conditions);
        $agent_params = array_merge([(int)$agent['id']], $params);
        $agent_types = 'i' . $types;
        
        $agent_query = "SELECT d.id, d.name,
            COALESCE(d.total_pledged, 0) as total_pledged,
            COALESCE(d.total_paid, 0) as total_paid,
            (COALESCE(d.total_pledged, 0) - COALESCE(d.total_paid, 0)) as balance
    FROM donors d
            WHERE " . implode(" AND ", $agent_where) . "
            ORDER BY {$order_by_clause}";
        
        $donors_by_agent[$agent['id']] = [];
        
        if (!empty($agent_params)) {
            $agent_stmt = $db->prepare($agent_query);
            $agent_stmt->bind_param($agent_types, ...$agent_params);
            $agent_stmt->execute();
            $donors_result = $agent_stmt->get_result();
            
            if ($donors_result) {
                while ($donor = $donors_result->fetch_assoc()) {
                    $donors_by_agent[$agent['id']][] = $donor;
                }
            }
        }
    }

    // Get unassigned donors (with filters)
    $unassigned_where = array_merge(["d.agent_id IS NULL"], $where_conditions);
    $unassigned_params = $params;
    $unassigned_types = $types;
    
    $unassigned_query = "SELECT d.id, d.name,
        COALESCE(d.total_pledged, 0) as total_pledged,
        COALESCE(d.total_paid, 0) as total_paid,
        (COALESCE(d.total_pledged, 0) - COALESCE(d.total_paid, 0)) as balance
        FROM donors d
        WHERE " . implode(" AND ", $unassigned_where) . "
        ORDER BY {$order_by_clause}";
    
    if (!empty($unassigned_params)) {
        $unassigned_stmt = $db->prepare($unassigned_query);
        $unassigned_stmt->bind_param($unassigned_types, ...$unassigned_params);
        $unassigned_stmt->execute();
        $unassigned_result = $unassigned_stmt->get_result();
    } else {
        $unassigned_result = $db->query($unassigned_query);
    }
    
    if ($unassigned_result) {
        while ($donor = $unassigned_result->fetch_assoc()) {
            $unassigned_donors[] = $donor;
        }
    }
} catch (Exception $e) {
    // Silently handle errors - page will still load
    error_log("Assign donors page error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $page_title; ?> - Fundraising Admin</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        :root {
            --primary-color: #0d6efd;
            --success-color: #198754;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --border-radius: 12px;
            --shadow-sm: 0 2px 4px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 12px rgba(0,0,0,0.1);
            --shadow-lg: 0 8px 24px rgba(0,0,0,0.12);
        }

        /* Bulk Actions Bar - Sticky Top */
        .bulk-actions-bar {
            position: sticky;
            top: 70px;
            z-index: 100;
            background: white;
            border: 1px solid #dee2e6;
            border-radius: var(--border-radius);
            padding: 16px 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-sm);
            display: none;
            transition: all 0.3s ease;
        }

        .bulk-actions-bar.show {
            display: block;
        }

        .bulk-actions-bar .badge-count {
            background: #0d6efd;
            color: white;
            padding: 6px 12px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.875rem;
        }

        .bulk-actions-bar select {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 8px 12px;
            min-width: 200px;
        }

        .bulk-actions-bar button {
            border-radius: 8px;
            padding: 8px 16px;
            font-weight: 500;
        }

        /* Modern Card Design */
        .modern-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            border: 1px solid rgba(0,0,0,0.05);
            transition: all 0.3s ease;
            overflow: hidden;
        }

        .modern-card:hover {
            box-shadow: var(--shadow-md);
        }

        .modern-card .card-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-bottom: 1px solid rgba(0,0,0,0.05);
            padding: 16px 20px;
            font-weight: 600;
        }

        /* Enhanced Table */
        .table-modern {
            margin: 0;
        }

        .table-modern thead th {
            background: #f8f9fa;
            font-weight: 600;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            border: none;
            padding: 16px 12px;
        }
        
        .sortable-header {
            cursor: pointer;
            user-select: none;
            transition: all 0.2s;
            text-decoration: none;
            color: #6c757d;
            display: inline-flex;
            align-items: center;
            padding: 4px 8px;
            border-radius: 4px;
            margin: -4px -8px;
        }
        
        .sortable-header:hover {
            color: var(--primary-color);
            background: rgba(13, 110, 253, 0.08);
            text-decoration: none;
        }
        
        .sortable-header:hover .fa-sort {
            opacity: 0.6 !important;
        }
        
        .sortable-header .fas {
            font-size: 0.75rem;
        }

        .table-modern tbody tr {
            transition: all 0.2s ease;
            border-bottom: 1px solid #f0f0f0;
        }

        .table-modern tbody tr:hover {
            background: #f8f9fa;
            transform: scale(1.01);
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .table-modern tbody td {
            padding: 16px 12px;
            vertical-align: middle;
        }

        .donor-checkbox {
            width: 20px;
            height: 20px;
            cursor: pointer;
            accent-color: var(--primary-color);
        }

        /* Modern Badges */
        .badge-modern {
            padding: 6px 12px;
            border-radius: 6px;
            font-weight: 500;
            font-size: 0.8125rem;
        }

        /* Enhanced Form Controls */
        .form-select-modern {
            border-radius: 8px;
            border: 1px solid #dee2e6;
            padding: 8px 12px;
            transition: all 0.2s;
        }

        .form-select-modern:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.1);
        }

        .btn-modern {
            border-radius: 8px;
            padding: 8px 16px;
            font-weight: 500;
            transition: all 0.2s;
            border: none;
        }

        .btn-modern:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        
        /* Filter Panel Styles */
        .filter-toggle-btn {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: var(--border-radius);
            padding: 12px 20px;
            margin-bottom: 16px;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            text-align: left;
        }
        
        .filter-toggle-btn:hover {
            background: #f8f9fa;
            border-color: var(--primary-color);
            box-shadow: var(--shadow-sm);
        }
        
        .filter-toggle-btn .badge {
            background: var(--primary-color);
            color: white;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .filter-panel {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: var(--border-radius);
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-sm);
        }
        
        .filter-panel .form-label {
            font-weight: 500;
            color: #495057;
            font-size: 0.875rem;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .filter-panel .form-control,
        .filter-panel .form-select {
            border-radius: 8px;
            border: 1px solid #dee2e6;
            transition: all 0.2s;
        }
        
        .filter-panel .form-control:focus,
        .filter-panel .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.1);
        }

        /* Enhanced Tabs */
        .nav-tabs-modern {
            border-bottom: 2px solid #e9ecef;
            margin-bottom: 24px;
        }

        .nav-tabs-modern .nav-link {
            border: none;
            border-bottom: 3px solid transparent;
            padding: 12px 24px;
            color: #6c757d;
            font-weight: 500;
            transition: all 0.2s;
            border-radius: 0;
        }

        .nav-tabs-modern .nav-link:hover {
            color: var(--primary-color);
            background: rgba(13, 110, 253, 0.05);
        }

        .nav-tabs-modern .nav-link.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
            background: transparent;
        }

        /* Modern Accordion */
        .accordion-modern .accordion-item {
            border: 1px solid #e9ecef;
            border-radius: var(--border-radius);
            margin-bottom: 12px;
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            transition: all 0.2s;
        }

        .accordion-modern .accordion-item:hover {
            box-shadow: var(--shadow-md);
        }

        .accordion-modern .accordion-button {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            font-weight: 600;
            padding: 16px 20px;
            border: none;
            box-shadow: none;
        }

        .accordion-modern .accordion-button:not(.collapsed) {
            background: linear-gradient(135deg, #e7f3ff 0%, #f0f8ff 100%);
            color: var(--primary-color);
        }

        .accordion-modern .accordion-body {
            padding: 20px;
        }

        /* Statistics Cards */
        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 20px;
            box-shadow: var(--shadow-sm);
            border-left: 4px solid var(--primary-color);
            transition: all 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            }

        /* Responsive Design */
        @media (max-width: 768px) {
            .bulk-actions-bar {
                top: 56px;
                padding: 12px 16px;
            }

            .bulk-actions-bar > div {
                flex-direction: column;
                align-items: stretch !important;
                gap: 12px;
            }

            .bulk-actions-bar .d-flex {
                flex-direction: column;
                align-items: stretch !important;
            }

            .bulk-actions-bar select {
                width: 100%;
                min-width: auto;
            }

            .bulk-actions-bar button {
                width: 100%;
            }

            .table-modern {
                font-size: 0.875rem;
            }

            .table-modern thead th,
            .table-modern tbody td {
                padding: 12px 8px;
            }

            .nav-tabs-modern .nav-link {
                padding: 10px 16px;
                font-size: 0.875rem;
            }

            .accordion-modern .accordion-button {
                padding: 12px 16px;
                font-size: 0.875rem;
            }

            .card-header {
                padding: 12px 16px;
            }
        }

        @media (max-width: 576px) {
            .table-responsive {
                font-size: 0.8125rem;
            }

            .btn-modern {
                padding: 6px 12px;
                font-size: 0.8125rem;
            }

            .form-select-modern {
                padding: 6px 10px;
                font-size: 0.8125rem;
            }
        }

        /* Loading State */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255,255,255,0.9);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }

        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Smooth Animations */
        * {
            transition: background-color 0.2s ease, color 0.2s ease;
        }

        /* Better Checkbox Styling */
        input[type="checkbox"] {
            cursor: pointer;
            width: 20px;
            height: 20px;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 4rem;
            opacity: 0.3;
            margin-bottom: 16px;
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        
        <main class="main-content">
            <!-- Page Header -->
            <div class="content-header mb-3">
                <div>
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back
                    </a>
                </div>
            </div>

            <?php if (isset($message)): ?>
                <div class="alert alert-info alert-dismissible fade show">
                    <i class="fas fa-info-circle me-2"></i>
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <?php
            $total_donors = 0;
            $assigned_count = 0;
            $unassigned_count = 0;
            
            try {
                // Build WHERE clause for filtered counts
                $count_where = $where_conditions;
                $count_params = $params;
                $count_types = $types;
                
                $where_clause = !empty($count_where) ? "WHERE " . implode(" AND ", $count_where) : "";
                
                // Total filtered donors
                $total_query = "SELECT COUNT(*) as count FROM donors d {$where_clause}";
                if (!empty($count_params)) {
                    $total_stmt = $db->prepare($total_query);
                    $total_stmt->bind_param($count_types, ...$count_params);
                    $total_stmt->execute();
                    $total_result = $total_stmt->get_result();
                    if ($total_result) {
                        $total_donors = (int)($total_result->fetch_assoc()['count'] ?? 0);
                    }
                } else {
                    $total_result = $db->query($total_query);
                    if ($total_result) {
                        $total_donors = (int)($total_result->fetch_assoc()['count'] ?? 0);
                    }
                }
                
                // Assigned filtered donors
                $assigned_where = array_merge($count_where, ["d.agent_id IS NOT NULL"]);
                $assigned_query = "SELECT COUNT(*) as count FROM donors d WHERE " . implode(" AND ", $assigned_where);
                if (!empty($count_params)) {
                    $assigned_stmt = $db->prepare($assigned_query);
                    $assigned_stmt->bind_param($count_types, ...$count_params);
                    $assigned_stmt->execute();
                    $assigned_result = $assigned_stmt->get_result();
                    if ($assigned_result) {
                        $assigned_count = (int)($assigned_result->fetch_assoc()['count'] ?? 0);
                    }
                } else {
                    $assigned_result = $db->query($assigned_query);
                    if ($assigned_result) {
                        $assigned_count = (int)($assigned_result->fetch_assoc()['count'] ?? 0);
                    }
                }
                
                // Unassigned filtered donors
                $unassigned_where = array_merge($count_where, ["d.agent_id IS NULL"]);
                $unassigned_query = "SELECT COUNT(*) as count FROM donors d WHERE " . implode(" AND ", $unassigned_where);
                if (!empty($count_params)) {
                    $unassigned_stmt = $db->prepare($unassigned_query);
                    $unassigned_stmt->bind_param($count_types, ...$count_params);
                    $unassigned_stmt->execute();
                    $unassigned_result = $unassigned_stmt->get_result();
                    if ($unassigned_result) {
                        $unassigned_count = (int)($unassigned_result->fetch_assoc()['count'] ?? 0);
                    }
                } else {
                    $unassigned_result = $db->query($unassigned_query);
                    if ($unassigned_result) {
                        $unassigned_count = (int)($unassigned_result->fetch_assoc()['count'] ?? 0);
                    }
                }
            } catch (Exception $e) {
                // Silently fail - show zeros
                error_log("Stats query error: " . $e->getMessage());
            }
            ?>
            <?php
            // Check if any filters are active
            $has_active_filters = !empty($filter_search) || $filter_min_pledge !== null || 
                                  $filter_max_pledge !== null || $filter_min_balance !== null || 
                                  $filter_max_balance !== null || $filter_registrar !== null || 
                                  $filter_assignment !== 'all' || $filter_donation_type !== 'all';
            ?>
            <div class="row g-2 mb-3">
                <div class="col-auto">
                    <div class="stat-card" style="border-left-color: #0d6efd; padding: 10px 16px;">
                        <div class="d-flex align-items-center gap-2">
                            <i class="fas fa-users text-primary"></i>
                            <span class="fw-bold"><?php echo number_format($total_donors); ?></span>
                            <small class="text-muted"><?php echo $has_active_filters ? 'Results' : 'Total Donors'; ?></small>
                                </div>
                            </div>
                        </div>
                <div class="col-auto">
                    <div class="stat-card" style="border-left-color: #198754; padding: 10px 16px;">
                        <div class="d-flex align-items-center gap-2">
                            <i class="fas fa-user-check text-success"></i>
                            <span class="fw-bold"><?php echo number_format($assigned_count); ?></span>
                            <small class="text-muted">Assigned</small>
                    </div>
                </div>
                                </div>
                <div class="col-auto">
                    <div class="stat-card" style="border-left-color: #ffc107; padding: 10px 16px;">
                        <div class="d-flex align-items-center gap-2">
                            <i class="fas fa-user-slash text-warning"></i>
                            <span class="fw-bold"><?php echo number_format($unassigned_count); ?></span>
                            <small class="text-muted">Unassigned</small>
                                </div>
                            </div>
                        </div>
                    </div>

            <!-- Filter Toggle Button -->
            <?php
            $active_filters = 0;
            if (!empty($filter_search)) $active_filters++;
            if ($filter_min_pledge !== null) $active_filters++;
            if ($filter_max_pledge !== null) $active_filters++;
            if ($filter_min_balance !== null) $active_filters++;
            if ($filter_max_balance !== null) $active_filters++;
            if ($filter_registrar !== null) $active_filters++;
            if ($filter_assignment !== 'all') $active_filters++;
            if ($filter_donation_type !== 'all') $active_filters++;
            ?>
            <button class="filter-toggle-btn" type="button" data-bs-toggle="collapse" data-bs-target="#filterPanel" aria-expanded="<?php echo $active_filters > 0 ? 'true' : 'false'; ?>">
                <div class="d-flex align-items-center gap-2">
                    <i class="fas fa-filter"></i>
                    <strong>Filters</strong>
                    <?php if ($active_filters > 0): ?>
                        <span class="badge"><?php echo $active_filters; ?> Active</span>
                    <?php endif; ?>
                </div>
                <i class="fas fa-chevron-down" id="filterChevron"></i>
            </button>

            <!-- Filter Panel -->
            <div class="collapse <?php echo $active_filters > 0 ? 'show' : ''; ?>" id="filterPanel">
                <div class="filter-panel">
                    <form method="GET" action="assign-donors.php" id="filterForm">
                        <div class="row g-3">
                            <!-- Search -->
                            <div class="col-md-6">
                                <label class="form-label">
                                    <i class="fas fa-search"></i>
                                    Search Name/Phone
                                </label>
                                <input type="text" name="search" class="form-control" 
                                       value="<?php echo htmlspecialchars($filter_search); ?>" 
                                       placeholder="Enter name or phone number">
                        </div>

                            <!-- Assignment Status -->
                            <div class="col-md-6">
                                <label class="form-label">
                                    <i class="fas fa-user-check"></i>
                                    Assignment Status
                                </label>
                            <select name="assignment" class="form-select">
                                    <option value="all" <?php echo $filter_assignment === 'all' ? 'selected' : ''; ?>>All Donors</option>
                                    <option value="assigned" <?php echo $filter_assignment === 'assigned' ? 'selected' : ''; ?>>Assigned Only</option>
                                    <option value="unassigned" <?php echo $filter_assignment === 'unassigned' ? 'selected' : ''; ?>>Unassigned Only</option>
                            </select>
                        </div>

                            <!-- Donation Type -->
                            <div class="col-md-6">
                                <label class="form-label">
                                    <i class="fas fa-hand-holding-usd"></i>
                                    Donation Type
                                </label>
                                <select name="donation_type" class="form-select">
                                    <option value="all" <?php echo $filter_donation_type === 'all' ? 'selected' : ''; ?>>All Types</option>
                                    <option value="pledge" <?php echo $filter_donation_type === 'pledge' ? 'selected' : ''; ?>>Pledges Only</option>
                                    <option value="payment" <?php echo $filter_donation_type === 'payment' ? 'selected' : ''; ?>>Payments Only</option>
                            </select>
                        </div>

                            <!-- Registrar Filter -->
                            <div class="col-md-6">
                                <label class="form-label">
                                    <i class="fas fa-user-tie"></i>
                                    Registered By (Registrar)
                                </label>
                                <select name="registrar" class="form-select">
                                    <option value="">All Registrars</option>
                                    <?php if (!empty($registrars)): ?>
                                        <?php foreach ($registrars as $registrar): ?>
                                            <option value="<?php echo $registrar['id']; ?>" <?php echo $filter_registrar == $registrar['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($registrar['name']); ?>
                                </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                            </select>
                        </div>

                            <!-- Pledge Amount Range -->
                            <div class="col-md-6">
                                <label class="form-label">
                                    <i class="fas fa-pound-sign"></i>
                                    Min Pledge Amount
                                </label>
                                <input type="number" name="min_pledge" class="form-control" 
                                       value="<?php echo $filter_min_pledge !== null ? $filter_min_pledge : ''; ?>" 
                                       placeholder="e.g., 400.00" step="0.01" min="0">
                        </div>

                            <div class="col-md-6">
                                <label class="form-label">
                                    <i class="fas fa-pound-sign"></i>
                                    Max Pledge Amount
                                </label>
                                <input type="number" name="max_pledge" class="form-control" 
                                       value="<?php echo $filter_max_pledge !== null ? $filter_max_pledge : ''; ?>" 
                                       placeholder="e.g., 1000.00" step="0.01" min="0">
                            </div>

                            <!-- Balance Range -->
                            <div class="col-md-6">
                                <label class="form-label">
                                    <i class="fas fa-coins"></i>
                                    Min Balance
                                </label>
                                <input type="number" name="min_balance" class="form-control" 
                                       value="<?php echo $filter_min_balance !== null ? $filter_min_balance : ''; ?>" 
                                       placeholder="e.g., 100.00" step="0.01" min="0">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">
                                    <i class="fas fa-coins"></i>
                                    Max Balance
                                </label>
                                <input type="number" name="max_balance" class="form-control" 
                                       value="<?php echo $filter_max_balance !== null ? $filter_max_balance : ''; ?>" 
                                       placeholder="e.g., 500.00" step="0.01" min="0">
                            </div>

                            <!-- Filter Actions -->
                            <div class="col-12">
                                <div class="d-flex gap-2 flex-wrap">
                                    <button type="submit" class="btn btn-primary btn-modern">
                                        <i class="fas fa-check me-1"></i>Apply Filters
                            </button>
                                    <a href="assign-donors.php" class="btn btn-outline-secondary btn-modern">
                                        <i class="fas fa-times me-1"></i>Clear All
                                    </a>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Loading Overlay -->
            <div class="loading-overlay" id="loadingOverlay">
                <div class="loading-spinner"></div>
            </div>

            <!-- Bulk Actions Bar -->
            <div class="bulk-actions-bar" id="bulkActionsBar">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                    <div class="d-flex align-items-center gap-3">
                        <span class="badge-count">
                            <i class="fas fa-check-circle me-1"></i>
                            <span id="selectedCount">0</span> selected
                        </span>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearSelection()">
                            <i class="fas fa-times me-1"></i>Clear
                        </button>
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <select id="bulkAgentSelect" class="form-select form-select-sm">
                            <option value="0">Select Agent...</option>
                            <?php if (!empty($agents)): ?>
                                <?php foreach ($agents as $agent): ?>
                                    <option value="<?php echo $agent['id']; ?>"><?php echo htmlspecialchars($agent['name']); ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                        <button type="button" class="btn btn-sm btn-success" onclick="bulkAssign()">
                            <i class="fas fa-user-plus me-1"></i>Assign
                        </button>
                        <button type="button" class="btn btn-sm btn-danger" onclick="bulkUnassign()">
                            <i class="fas fa-user-minus me-1"></i>Unassign
                        </button>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <ul class="nav nav-tabs nav-tabs-modern" id="myTab" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="all-tab" data-bs-toggle="tab" data-bs-target="#all" type="button" role="tab">
                        <i class="fas fa-list me-2"></i>All Donors
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="agents-tab" data-bs-toggle="tab" data-bs-target="#agents" type="button" role="tab">
                        <i class="fas fa-users me-2"></i>By Agents
                    </button>
                </li>
            </ul>
            
            <div class="tab-content" id="myTabContent">
                <!-- All Donors Tab -->
                <div class="tab-pane fade show active" id="all" role="tabpanel">
                    <div class="modern-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">
                                <i class="fas fa-users me-2"></i>All Donors
                    </h6>
                            <div class="d-flex align-items-center gap-2">
                                <input type="checkbox" id="selectAllAll" class="donor-checkbox" onchange="toggleSelectAll('all')">
                                <label for="selectAllAll" class="mb-0 small">Select All</label>
                    </div>
                </div>
                <div class="card-body p-0">
                            <?php
                            try {
                                // Build WHERE clause for All Donors tab
                                $all_where = $where_conditions;
                                $all_params = $params;
                                $all_types = $types;
                                
                                $where_clause = !empty($all_where) ? "WHERE " . implode(" AND ", $all_where) : "";
                                
                                $all_query = "SELECT d.id, d.name, d.agent_id,
                                    COALESCE(d.total_pledged, 0) as total_pledged,
                                    COALESCE(d.total_paid, 0) as total_paid,
                                    (COALESCE(d.total_pledged, 0) - COALESCE(d.total_paid, 0)) as balance,
                                    u.name as agent_name
                                    FROM donors d
                                    LEFT JOIN users u ON d.agent_id = u.id
                                    {$where_clause}
                                    ORDER BY {$order_by_clause}
                                    LIMIT 100";
                                
                                if (!empty($all_params)) {
                                    $all_stmt = $db->prepare($all_query);
                                    $all_stmt->bind_param($all_types, ...$all_params);
                                    $all_stmt->execute();
                                    $result = $all_stmt->get_result();
                                } else {
                                    $result = $db->query($all_query);
                                }
                                
                                if ($result && $result->num_rows > 0) {
                                    echo "<div class='table-responsive'>";
                                    echo "<table class='table table-modern mb-0'>";
                                    echo "<thead>";
                                    echo "<tr>";
                                    echo "<th style='width: 50px;'><input type='checkbox' id='selectAllAllHeader' class='donor-checkbox' onchange='toggleSelectAll(\"all\")'></th>";
                                    echo "<th><a href='" . getSortUrl('id', $sort_by, $sort_order) . "' class='sortable-header'>ID" . getSortIcon('id', $sort_by, $sort_order) . "</a></th>";
                                    echo "<th><a href='" . getSortUrl('name', $sort_by, $sort_order) . "' class='sortable-header'>Name" . getSortIcon('name', $sort_by, $sort_order) . "</a></th>";
                                    echo "<th><a href='" . getSortUrl('pledge', $sort_by, $sort_order) . "' class='sortable-header'>Pledge Amount" . getSortIcon('pledge', $sort_by, $sort_order) . "</a></th>";
                                    echo "<th><a href='" . getSortUrl('balance', $sort_by, $sort_order) . "' class='sortable-header'>Balance" . getSortIcon('balance', $sort_by, $sort_order) . "</a></th>";
                                    echo "<th>Assigned To</th>";
                                    echo "<th style='min-width: 280px;'>Actions</th>";
                                    echo "</tr>";
                                    echo "</thead>";
                                    echo "<tbody>";
                                    while ($row = $result->fetch_assoc()) {
                                        $balance = (float)$row['balance'];
                                        $pledge = (float)$row['total_pledged'];
                                        echo "<tr>";
                                        echo "<td><input type='checkbox' class='donor-checkbox' name='donor_ids[]' value='" . $row['id'] . "' onchange='updateBulkBar()'></td>";
                                        echo "<td><span class='text-muted'>#" . $row['id'] . "</span></td>";
                                        echo "<td><strong>" . htmlspecialchars($row['name']) . "</strong></td>";
                                        echo "<td><span class='fw-semibold'>£" . number_format($pledge, 2) . "</span></td>";
                                        echo "<td><span class='badge badge-modern bg-" . ($balance > 0 ? 'warning' : 'success') . "'>£" . number_format($balance, 2) . "</span></td>";
                                        echo "<td>" . ($row['agent_name'] ? '<span class="badge badge-modern bg-primary"><i class="fas fa-user me-1"></i>' . htmlspecialchars($row['agent_name']) . '</span>' : '<span class="text-muted"><i class="fas fa-user-slash me-1"></i>Unassigned</span>') . "</td>";
                                        echo "<td>";
                                        
                                        if (!empty($row['agent_id'])) {
                                            // Donor is assigned - show unassign button
                                            echo "<form method='POST' class='d-flex gap-2 align-items-center'>";
                                            echo "<input type='hidden' name='donor_id' value='" . $row['id'] . "'>";
                                            echo "<input type='hidden' name='agent_id' value='0'>";
                                            echo "<button type='submit' name='assign' class='btn btn-sm btn-danger btn-modern'>";
                                            echo "<i class='fas fa-user-minus me-1'></i>Unassign";
                                            echo "</button>";
                                            echo "</form>";
                                        } else {
                                            // Donor is not assigned - show assign dropdown
                                            echo "<form method='POST' class='d-flex gap-2 align-items-center'>";
                                            echo "<input type='hidden' name='donor_id' value='" . $row['id'] . "'>";
                                            echo "<select name='agent_id' class='form-select form-select-sm form-select-modern' style='flex: 1; min-width: 150px;'>";
                                            echo "<option value='0'>Select Agent...</option>";
                                            if (!empty($agents)) {
                                                foreach ($agents as $agent) {
                                                    echo "<option value='" . $agent['id'] . "'>" . htmlspecialchars($agent['name']) . "</option>";
                                                }
                                            }
                                            echo "</select>";
                                            echo "<button type='submit' name='assign' class='btn btn-sm btn-primary btn-modern'>";
                                            echo "<i class='fas fa-check me-1'></i>Assign";
                                            echo "</button>";
                                            echo "</form>";
                                        }
                                        
                                        echo "</td>";
                                        echo "</tr>";
                                    }
                                    echo "</tbody></table>";
                                    echo "</div>";
                                } else {
                                    echo "<div class='empty-state'>";
                                    echo "<i class='fas fa-users-slash'></i>";
                                    echo "<p class='mt-3'>No donors found</p>";
                                    echo "</div>";
                                }
                            } catch (Exception $e) {
                                echo "<div class='alert alert-danger m-3'>";
                                echo "<i class='fas fa-exclamation-triangle me-2'></i>";
                                echo "Error: " . htmlspecialchars($e->getMessage());
                                echo "</div>";
                            }
                            ?>
                                        </div>
                                    </div>
                                </div>
                
                <!-- By Agents Tab -->
                <div class="tab-pane fade" id="agents" role="tabpanel">
                    <div class="accordion accordion-modern" id="agentsAccordion">
                        <?php
                        $accordion_index = 0;
                        if (!empty($agents)) {
                            foreach ($agents as $agent):
                                $donors = $donors_by_agent[$agent['id']] ?? [];
                                $count = count($donors);
                                $accordion_index++;
                        ?>
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="heading<?php echo $accordion_index; ?>">
                                <button class="accordion-button <?php echo $accordion_index === 1 ? '' : 'collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?php echo $accordion_index; ?>">
                                    <i class="fas fa-user me-2"></i>
                                    <?php echo htmlspecialchars($agent['name']); ?> 
                                    <span class="badge bg-primary ms-2"><?php echo $count; ?> donor<?php echo $count !== 1 ? 's' : ''; ?></span>
                                </button>
                            </h2>
                            <div id="collapse<?php echo $accordion_index; ?>" class="accordion-collapse collapse <?php echo $accordion_index === 1 ? 'show' : ''; ?>" data-bs-parent="#agentsAccordion">
                                <div class="accordion-body">
                                    <?php if ($count > 0): ?>
                                        <div class="mb-3 d-flex align-items-center gap-2">
                                            <input type="checkbox" class="select-all-agent donor-checkbox" data-agent="<?php echo $agent['id']; ?>" onchange="toggleSelectAllAgent(<?php echo $agent['id']; ?>)">
                                            <label class="mb-0 small">Select All</label>
                            </div>
                                        <div class="table-responsive">
                                            <table class="table table-modern table-sm">
                                                <thead>
                                                    <tr>
                                                        <th style="width: 50px;"></th>
                                                        <th><a href="<?php echo getSortUrl('id', $sort_by, $sort_order); ?>" class="sortable-header">ID<?php echo getSortIcon('id', $sort_by, $sort_order); ?></a></th>
                                                        <th><a href="<?php echo getSortUrl('name', $sort_by, $sort_order); ?>" class="sortable-header">Name<?php echo getSortIcon('name', $sort_by, $sort_order); ?></a></th>
                                                        <th><a href="<?php echo getSortUrl('pledge', $sort_by, $sort_order); ?>" class="sortable-header">Pledge Amount<?php echo getSortIcon('pledge', $sort_by, $sort_order); ?></a></th>
                                                        <th><a href="<?php echo getSortUrl('balance', $sort_by, $sort_order); ?>" class="sortable-header">Balance<?php echo getSortIcon('balance', $sort_by, $sort_order); ?></a></th>
                                                        <th>Action</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($donors as $donor): ?>
                                                    <tr>
                                                        <td><input type="checkbox" class="donor-checkbox" name="donor_ids[]" value="<?php echo $donor['id']; ?>" onchange="updateBulkBar()"></td>
                                                        <td><span class="text-muted">#<?php echo $donor['id']; ?></span></td>
                                                        <td><strong><?php echo htmlspecialchars($donor['name']); ?></strong></td>
                                                        <td><span class="fw-semibold">£<?php echo number_format((float)$donor['total_pledged'], 2); ?></span></td>
                                                        <td><span class="badge badge-modern bg-<?php echo $donor['balance'] > 0 ? 'warning' : 'success'; ?>">£<?php echo number_format((float)$donor['balance'], 2); ?></span></td>
                                                        <td>
                                                            <form method="POST" style="display: inline;">
                                                                <input type="hidden" name="donor_id" value="<?php echo $donor['id']; ?>">
                                                                <input type="hidden" name="agent_id" value="0">
                                                                <button type="submit" name="assign" class="btn btn-sm btn-outline-danger btn-modern">
                                                                    <i class="fas fa-user-minus me-1"></i>Unassign
                                                                </button>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                        </div>
                    <?php else: ?>
                                        <div class="empty-state py-4">
                                            <i class="fas fa-inbox"></i>
                                            <p class="mt-2 mb-0">No donors assigned to this agent.</p>
                        </div>
                    <?php endif; ?>
                </div>
                    </div>
                </div>
                        <?php 
                            endforeach;
                        }
                        ?>

                        <!-- Unassigned Donors -->
                        <?php if (count($unassigned_donors) > 0): ?>
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="headingUnassigned">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseUnassigned">
                                    <i class="fas fa-user-slash me-2"></i>
                                    Unassigned Donors
                                    <span class="badge bg-secondary ms-2"><?php echo count($unassigned_donors); ?> donor<?php echo count($unassigned_donors) !== 1 ? 's' : ''; ?></span>
                                </button>
                            </h2>
                            <div id="collapseUnassigned" class="accordion-collapse collapse" data-bs-parent="#agentsAccordion">
                                <div class="accordion-body">
                                    <div class="mb-3 d-flex align-items-center gap-2">
                                        <input type="checkbox" class="select-all-unassigned donor-checkbox" onchange="toggleSelectAllUnassigned()">
                                        <label class="mb-0 small">Select All</label>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-modern table-sm">
                                            <thead>
                                                <tr>
                                                    <th style="width: 50px;"></th>
                                                    <th><a href="<?php echo getSortUrl('id', $sort_by, $sort_order); ?>" class="sortable-header">ID<?php echo getSortIcon('id', $sort_by, $sort_order); ?></a></th>
                                                    <th><a href="<?php echo getSortUrl('name', $sort_by, $sort_order); ?>" class="sortable-header">Name<?php echo getSortIcon('name', $sort_by, $sort_order); ?></a></th>
                                                    <th><a href="<?php echo getSortUrl('pledge', $sort_by, $sort_order); ?>" class="sortable-header">Pledge Amount<?php echo getSortIcon('pledge', $sort_by, $sort_order); ?></a></th>
                                                    <th><a href="<?php echo getSortUrl('balance', $sort_by, $sort_order); ?>" class="sortable-header">Balance<?php echo getSortIcon('balance', $sort_by, $sort_order); ?></a></th>
                                                    <th>Assign</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($unassigned_donors as $donor): ?>
                                                <tr>
                                                    <td><input type="checkbox" class="donor-checkbox" name="donor_ids[]" value="<?php echo $donor['id']; ?>" onchange="updateBulkBar()"></td>
                                                    <td><span class="text-muted">#<?php echo $donor['id']; ?></span></td>
                                                    <td><strong><?php echo htmlspecialchars($donor['name']); ?></strong></td>
                                                    <td><span class="fw-semibold">£<?php echo number_format((float)$donor['total_pledged'], 2); ?></span></td>
                                                    <td><span class="badge badge-modern bg-<?php echo $donor['balance'] > 0 ? 'warning' : 'success'; ?>">£<?php echo number_format((float)$donor['balance'], 2); ?></span></td>
                                                    <td>
                                                        <form method="POST" class="d-flex gap-2 align-items-center">
                                                            <input type="hidden" name="donor_id" value="<?php echo $donor['id']; ?>">
                                                            <select name="agent_id" class="form-select form-select-sm form-select-modern" style="flex: 1; min-width: 150px;">
                                                                <option value="0">Select...</option>
                                                                <?php if (!empty($agents)): ?>
                                                                    <?php foreach ($agents as $agent): ?>
                                                                        <option value="<?php echo $agent['id']; ?>"><?php echo htmlspecialchars($agent['name']); ?></option>
                                                                    <?php endforeach; ?>
                <?php endif; ?>
                                                            </select>
                                                            <button type="submit" name="assign" class="btn btn-sm btn-primary btn-modern">
                                                                <i class="fas fa-user-plus me-1"></i>Assign
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
            </div>
    </div>
</div>
            </div>
                <?php endif; ?>
                </div>
            </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
function updateBulkBar() {
    const checkboxes = document.querySelectorAll('.donor-checkbox:checked');
    const count = checkboxes.length;
    const bar = document.getElementById('bulkActionsBar');
    
    if (count > 0) {
        bar.classList.add('show');
        document.getElementById('selectedCount').textContent = count;
    } else {
        bar.classList.remove('show');
    }
}

function clearSelection() {
    document.querySelectorAll('.donor-checkbox').forEach(cb => cb.checked = false);
    document.querySelectorAll('.select-all-agent, .select-all-unassigned, #selectAllAll, #selectAllAllHeader').forEach(cb => cb.checked = false);
    updateBulkBar();
}

function toggleSelectAll(tab) {
    const checkboxes = document.querySelectorAll('#all .donor-checkbox');
    const selectAll = document.getElementById('selectAllAll');
    const selectAllHeader = document.getElementById('selectAllAllHeader');
    const checked = selectAll.checked || selectAllHeader.checked;
    
    checkboxes.forEach(cb => cb.checked = checked);
    selectAll.checked = checked;
    selectAllHeader.checked = checked;
    updateBulkBar();
}

function toggleSelectAllAgent(agentId) {
    const accordion = document.querySelector(`#collapse${agentId}`);
    if (!accordion) return;
    const checkboxes = accordion.querySelectorAll('.donor-checkbox');
    const selectAll = accordion.querySelector('.select-all-agent');
    if (!selectAll) return;
    const checked = selectAll.checked;
    
    checkboxes.forEach(cb => cb.checked = checked);
    updateBulkBar();
}

function toggleSelectAllUnassigned() {
    const accordion = document.getElementById('collapseUnassigned');
    if (!accordion) return;
    const checkboxes = accordion.querySelectorAll('.donor-checkbox');
    const selectAll = accordion.querySelector('.select-all-unassigned');
    if (!selectAll) return;
    const checked = selectAll.checked;
    
    checkboxes.forEach(cb => cb.checked = checked);
    updateBulkBar();
}

function showLoading() {
    document.getElementById('loadingOverlay').style.display = 'flex';
}

function hideLoading() {
    document.getElementById('loadingOverlay').style.display = 'none';
}

function bulkAssign() {
    const checkboxes = document.querySelectorAll('.donor-checkbox:checked');
    const agentId = document.getElementById('bulkAgentSelect').value;
    
    if (checkboxes.length === 0) {
        alert('Please select at least one donor');
        return;
    }
    
    if (agentId === '0') {
        alert('Please select an agent');
        return;
    }
    
    if (!confirm(`Assign ${checkboxes.length} donor(s) to selected agent?`)) {
        return;
    }
    
    showLoading();
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = '<input type="hidden" name="bulk_action" value="assign">';
    form.innerHTML += '<input type="hidden" name="bulk_agent_id" value="' + agentId + '">';
    
    checkboxes.forEach(cb => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'donor_ids[]';
        input.value = cb.value;
        form.appendChild(input);
    });
    
    document.body.appendChild(form);
    form.submit();
}

function bulkUnassign() {
    const checkboxes = document.querySelectorAll('.donor-checkbox:checked');
    
    if (checkboxes.length === 0) {
        alert('Please select at least one donor');
        return;
    }
    
    if (!confirm(`Unassign ${checkboxes.length} donor(s)?`)) {
        return;
    }
    
    showLoading();
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = '<input type="hidden" name="bulk_action" value="unassign">';
    
    checkboxes.forEach(cb => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'donor_ids[]';
        input.value = cb.value;
        form.appendChild(input);
    });
    
    document.body.appendChild(form);
    form.submit();
}

// Toggle filter chevron icon
const filterPanel = document.getElementById('filterPanel');
const filterChevron = document.getElementById('filterChevron');
    
if (filterPanel && filterChevron) {
    filterPanel.addEventListener('show.bs.collapse', function () {
        filterChevron.classList.remove('fa-chevron-down');
        filterChevron.classList.add('fa-chevron-up');
    });
    
    filterPanel.addEventListener('hide.bs.collapse', function () {
        filterChevron.classList.remove('fa-chevron-up');
        filterChevron.classList.add('fa-chevron-down');
    });
}

</script>
</body>
</html>
