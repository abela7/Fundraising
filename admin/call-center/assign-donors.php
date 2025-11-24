<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_admin();

$db = db();
$page_title = 'Assign Donors to Agents';

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

// Initialize variables
$agents = [];
$donors_by_agent = [];
$unassigned_donors = [];

try {
    // Get agents
    $agents_result = $db->query("SELECT id, name FROM users WHERE role IN ('admin', 'registrar') ORDER BY name");
    if ($agents_result) {
        while ($agent = $agents_result->fetch_assoc()) {
            $agents[] = $agent;
        }
    }

    // Get donors by agent
    foreach ($agents as $agent) {
        $donors_result = $db->query("SELECT d.id, d.name,
            COALESCE(d.total_pledged, 0) as total_pledged,
            COALESCE(d.total_paid, 0) as total_paid,
            (COALESCE(d.total_pledged, 0) - COALESCE(d.total_paid, 0)) as balance
            FROM donors d
            WHERE d.agent_id = " . (int)$agent['id'] . "
            ORDER BY d.name");
        
        $donors_by_agent[$agent['id']] = [];
        if ($donors_result) {
            while ($donor = $donors_result->fetch_assoc()) {
                $donors_by_agent[$agent['id']][] = $donor;
            }
        }
    }

    // Get unassigned donors
    $unassigned_result = $db->query("SELECT d.id, d.name,
        COALESCE(d.total_pledged, 0) as total_pledged,
        COALESCE(d.total_paid, 0) as total_paid,
        (COALESCE(d.total_pledged, 0) - COALESCE(d.total_paid, 0)) as balance
    FROM donors d
        WHERE d.agent_id IS NULL
        ORDER BY d.name");
    
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

        /* Modern Bulk Actions Bar - Floating */
        .bulk-actions-bar {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%) translateY(100px);
            z-index: 1050;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 16px 24px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-lg);
            display: flex;
            align-items: center;
            gap: 16px;
            min-width: 90%;
            max-width: 600px;
            opacity: 0;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(10px);
        }

        .bulk-actions-bar.show {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }

        .bulk-actions-bar .badge-count {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.875rem;
        }

        .bulk-actions-bar select,
        .bulk-actions-bar button {
            border: none;
            border-radius: 8px;
            font-weight: 500;
        }

        .bulk-actions-bar select {
            background: white;
            padding: 8px 12px;
            min-width: 180px;
        }

        .bulk-actions-bar button {
            padding: 8px 20px;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .bulk-actions-bar button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
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
                bottom: 10px;
                left: 10px;
                right: 10px;
                transform: translateX(0) translateY(100px);
                max-width: none;
                flex-direction: column;
                align-items: stretch;
            }

            .bulk-actions-bar.show {
                transform: translateX(0) translateY(0);
            }

            .bulk-actions-bar > div {
                flex-direction: column;
                gap: 8px;
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
            <div class="content-header mb-4">
                <div>
                    <h1 class="content-title">
                        <i class="fas fa-users-cog me-2"></i>Assign Donors to Agents
                    </h1>
                    <p class="text-muted mb-0">Select donors and assign them to agents for follow-up</p>
                </div>
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
                $total_result = $db->query("SELECT COUNT(*) as count FROM donors");
                if ($total_result) {
                    $total_donors = (int)($total_result->fetch_assoc()['count'] ?? 0);
                }
                
                $assigned_result = $db->query("SELECT COUNT(*) as count FROM donors WHERE agent_id IS NOT NULL");
                if ($assigned_result) {
                    $assigned_count = (int)($assigned_result->fetch_assoc()['count'] ?? 0);
                }
                
                $unassigned_result = $db->query("SELECT COUNT(*) as count FROM donors WHERE agent_id IS NULL");
                if ($unassigned_result) {
                    $unassigned_count = (int)($unassigned_result->fetch_assoc()['count'] ?? 0);
                }
            } catch (Exception $e) {
                // Silently fail - show zeros
                error_log("Stats query error: " . $e->getMessage());
            }
            ?>
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="stat-card" style="border-left-color: #0d6efd;">
                            <div class="d-flex align-items-center">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-users fa-2x text-primary"></i>
                                </div>
                                <div class="flex-grow-1 ms-3">
                                <h4 class="mb-0"><?php echo number_format($total_donors); ?></h4>
                                <small class="text-muted">Total Donors</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card" style="border-left-color: #198754;">
                            <div class="d-flex align-items-center">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-user-check fa-2x text-success"></i>
                                </div>
                                <div class="flex-grow-1 ms-3">
                                <h4 class="mb-0"><?php echo number_format($assigned_count); ?></h4>
                                <small class="text-muted">Assigned</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card" style="border-left-color: #ffc107;">
                            <div class="d-flex align-items-center">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-user-slash fa-2x text-warning"></i>
                                </div>
                                <div class="flex-grow-1 ms-3">
                                <h4 class="mb-0"><?php echo number_format($unassigned_count); ?></h4>
                                <small class="text-muted">Unassigned</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Loading Overlay -->
            <div class="loading-overlay" id="loadingOverlay">
                <div class="loading-spinner"></div>
            </div>

            <!-- Bulk Actions Bar - Floating -->
            <div class="bulk-actions-bar" id="bulkActionsBar">
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <span class="badge-count">
                        <i class="fas fa-check-circle me-1"></i>
                        <span id="selectedCount">0</span> selected
                        </span>
                    <select id="bulkAgentSelect" class="form-select form-select-sm">
                        <option value="0">Select Agent...</option>
                        <?php if (!empty($agents)): ?>
                            <?php foreach ($agents as $agent): ?>
                                <option value="<?php echo $agent['id']; ?>"><?php echo htmlspecialchars($agent['name']); ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </select>
                    <button type="button" class="btn btn-sm btn-light" onclick="clearSelection()">
                        <i class="fas fa-times me-1"></i>Clear
                    </button>
                    <button type="button" class="btn btn-sm btn-success btn-modern" onclick="bulkAssign()">
                        <i class="fas fa-user-plus me-1"></i>Assign
                        </button>
                    <button type="button" class="btn btn-sm btn-danger btn-modern" onclick="bulkUnassign()">
                        <i class="fas fa-user-minus me-1"></i>Unassign
                        </button>
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
                                $result = $db->query("SELECT d.id, d.name, d.agent_id,
                                    COALESCE(d.total_pledged, 0) as total_pledged,
                                    COALESCE(d.total_paid, 0) as total_paid,
                                    (COALESCE(d.total_pledged, 0) - COALESCE(d.total_paid, 0)) as balance,
                                    u.name as agent_name
                                    FROM donors d
                                    LEFT JOIN users u ON d.agent_id = u.id
                                    ORDER BY d.name
                                    LIMIT 100");
                                
                                if ($result && $result->num_rows > 0) {
                                    echo "<div class='table-responsive'>";
                                    echo "<table class='table table-modern mb-0'>";
                                    echo "<thead>";
                                    echo "<tr>";
                                    echo "<th style='width: 50px;'><input type='checkbox' id='selectAllAllHeader' class='donor-checkbox' onchange='toggleSelectAll(\"all\")'></th>";
                                    echo "<th>ID</th>";
                                    echo "<th>Name</th>";
                                    echo "<th>Pledge Amount</th>";
                                    echo "<th>Balance</th>";
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
                                                        <th>ID</th>
                                                        <th>Name</th>
                                                        <th>Pledge Amount</th>
                                                        <th>Balance</th>
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
                                                    <th>ID</th>
                                                    <th>Name</th>
                                                    <th>Pledge Amount</th>
                                                    <th>Balance</th>
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

// Add smooth scroll behavior
document.addEventListener('DOMContentLoaded', function() {
    // Smooth scroll to top when bulk actions bar appears
    const observer = new MutationObserver(function(mutations) {
        const bar = document.getElementById('bulkActionsBar');
        if (bar.classList.contains('show')) {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    });
    
    observer.observe(document.getElementById('bulkActionsBar'), {
        attributes: true,
        attributeFilter: ['class']
    });
    });
</script>
</body>
</html>
