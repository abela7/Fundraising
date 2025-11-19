<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

$db = db();
$page_title = 'Assign Donors to Churches';

// Logging function
function log_action($message) {
    $log_file = __DIR__ . '/../../logs/assign_donors.log';
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[{$timestamp}] {$message}\n";
    file_put_contents($log_file, $log_message, FILE_APPEND);
}

// Handle form submission
$success_message = null;
$error_message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_donor'])) {
    $donor_id = (int)($_POST['donor_id'] ?? 0);
    $church_id = (int)($_POST['church_id'] ?? 0);
    $representative_id = (int)($_POST['representative_id'] ?? 0);
    
    log_action("Assignment attempt - Donor ID: {$donor_id}, Church ID: {$church_id}, Representative ID: {$representative_id}");
    
    $errors = [];
    
    if ($donor_id <= 0) {
        $errors[] = "Please select a donor.";
    }
    
    if ($church_id <= 0) {
        $errors[] = "Please select a church.";
    }
    
    if ($representative_id <= 0) {
        $errors[] = "Please select a representative.";
    }
    
    if (empty($errors)) {
        try {
            $db->begin_transaction();
            
            // Get current donor info
            // Check if representative_id column exists first
            $check_column = $db->query("SHOW COLUMNS FROM donors LIKE 'representative_id'");
            $has_rep_column = $check_column && $check_column->num_rows > 0;
            
            if ($has_rep_column) {
                $donor_stmt = $db->prepare("SELECT id, name, phone, church_id, representative_id FROM donors WHERE id = ?");
            } else {
                $donor_stmt = $db->prepare("SELECT id, name, phone, church_id FROM donors WHERE id = ?");
            }
            $donor_stmt->bind_param("i", $donor_id);
            $donor_stmt->execute();
            $current_donor = $donor_stmt->get_result()->fetch_assoc();
            
            if (!$current_donor) {
                throw new Exception("Donor not found.");
            }
            
            $old_church_id = $current_donor['church_id'];
            
            $old_representative_id = $current_donor['representative_id'] ?? null;
            
            log_action("Donor found - Name: {$current_donor['name']}, Current Church ID: " . ($old_church_id ?? 'NULL') . ", Current Rep ID: " . ($old_representative_id ?? 'NULL'));
            
            // Update donor's church_id AND representative_id
            // Check if representative_id column exists
            $check_column = $db->query("SHOW COLUMNS FROM donors LIKE 'representative_id'");
            if ($check_column && $check_column->num_rows > 0) {
                // Column exists, update both
                $update_stmt = $db->prepare("UPDATE donors SET church_id = ?, representative_id = ? WHERE id = ?");
                $update_stmt->bind_param("iii", $church_id, $representative_id, $donor_id);
            } else {
                // Column doesn't exist yet, only update church_id
                $update_stmt = $db->prepare("UPDATE donors SET church_id = ? WHERE id = ?");
                $update_stmt->bind_param("ii", $church_id, $donor_id);
            }
            
            if (!$update_stmt->execute()) {
                throw new Exception("Failed to update donor: " . $update_stmt->error);
            }
            
            log_action("Donor updated successfully - Donor ID: {$donor_id}, Old Church: " . ($old_church_id ?? 'NULL') . ", New Church: {$church_id}, Old Rep: " . ($old_representative_id ?? 'NULL') . ", New Rep: {$representative_id}");
            
            // Get representative info for logging
            $rep_info = '';
            if ($representative_id > 0) {
                $rep_stmt = $db->prepare("SELECT name, role FROM church_representatives WHERE id = ?");
                $rep_stmt->bind_param("i", $representative_id);
                $rep_stmt->execute();
                $rep_result = $rep_stmt->get_result()->fetch_assoc();
                if ($rep_result) {
                    $rep_info = "Representative: {$rep_result['name']} ({$rep_result['role']})";
                    log_action("Representative assigned - {$rep_info}");
                }
            }
            
            $db->commit();
            
            $success_message = "Donor '{$current_donor['name']}' has been assigned successfully!";
            if ($rep_info) {
                $success_message .= " " . $rep_info;
            }
            log_action("Assignment completed successfully - Donor ID: {$donor_id}");
            
            // Reset form
            $_GET = [];
            
        } catch (Exception $e) {
            $db->rollback();
            $error_message = "Error: " . $e->getMessage();
            log_action("Assignment failed - Error: " . $e->getMessage());
        }
    } else {
        $error_message = implode(" ", $errors);
        log_action("Validation failed - " . $error_message);
    }
}

// Fetch donors with search/filter
$donors = [];
$search = $_GET['search'] ?? '';
$city_filter = $_GET['city_filter'] ?? '';
$donor_type_filter = $_GET['donor_type_filter'] ?? '';
$payment_status_filter = $_GET['payment_status_filter'] ?? '';
$assignment_status_filter = $_GET['assignment_status_filter'] ?? '';
$amount_range_filter = $_GET['amount_range_filter'] ?? '';
$amount_min = $_GET['amount_min'] ?? '';
$amount_max = $_GET['amount_max'] ?? '';

// Only fetch if at least one filter is applied
// Amount filter only counts if both type and at least one value (min/max) is provided
$has_amount_filter = !empty($amount_range_filter) && (!empty($amount_min) || !empty($amount_max));
$has_filters = !empty($search) || !empty($city_filter) || !empty($donor_type_filter) || 
               !empty($payment_status_filter) || !empty($assignment_status_filter) || $has_amount_filter;

try {
    if ($has_filters) {
        $donor_query = "
            SELECT 
                d.id,
                d.name,
                d.phone,
                d.city,
                d.donor_type,
                d.payment_status,
                d.total_pledged,
                d.total_paid,
                d.balance,
                d.church_id,
                c.name as church_name,
                c.city as church_city,
                (SELECT COUNT(*) FROM church_representatives WHERE church_id = d.church_id AND is_active = 1) as rep_count
            FROM donors d
            LEFT JOIN churches c ON d.church_id = c.id
            WHERE 1=1
        ";
        
        $params = [];
        $types = '';
        
        if (!empty($search)) {
            $donor_query .= " AND (d.name LIKE ? OR d.phone LIKE ?)";
            $search_param = "%{$search}%";
            $params[] = $search_param;
            $params[] = $search_param;
            $types .= 'ss';
        }
        
        if (!empty($city_filter)) {
            $donor_query .= " AND d.city = ?";
            $params[] = $city_filter;
            $types .= 's';
        }
        
        if (!empty($donor_type_filter)) {
            $donor_query .= " AND d.donor_type = ?";
            $params[] = $donor_type_filter;
            $types .= 's';
        }
        
        if (!empty($payment_status_filter)) {
            $donor_query .= " AND d.payment_status = ?";
            $params[] = $payment_status_filter;
            $types .= 's';
        }
        
        if ($assignment_status_filter === 'assigned') {
            $donor_query .= " AND d.church_id IS NOT NULL";
        } elseif ($assignment_status_filter === 'unassigned') {
            $donor_query .= " AND d.church_id IS NULL";
        }
        
        // Amount filters (only apply if type is selected AND at least min or max is provided)
        if (!empty($amount_range_filter) && (!empty($amount_min) || !empty($amount_max))) {
            if ($amount_range_filter === 'pledged') {
                if ($amount_min !== '' && $amount_min !== null) {
                    $donor_query .= " AND d.total_pledged >= ?";
                    $params[] = (float)$amount_min;
                    $types .= 'd';
                }
                if ($amount_max !== '' && $amount_max !== null) {
                    $donor_query .= " AND d.total_pledged <= ?";
                    $params[] = (float)$amount_max;
                    $types .= 'd';
                }
            } elseif ($amount_range_filter === 'paid') {
                if ($amount_min !== '' && $amount_min !== null) {
                    $donor_query .= " AND d.total_paid >= ?";
                    $params[] = (float)$amount_min;
                    $types .= 'd';
                }
                if ($amount_max !== '' && $amount_max !== null) {
                    $donor_query .= " AND d.total_paid <= ?";
                    $params[] = (float)$amount_max;
                    $types .= 'd';
                }
            } elseif ($amount_range_filter === 'balance') {
                if ($amount_min !== '' && $amount_min !== null) {
                    $donor_query .= " AND d.balance >= ?";
                    $params[] = (float)$amount_min;
                    $types .= 'd';
                }
                if ($amount_max !== '' && $amount_max !== null) {
                    $donor_query .= " AND d.balance <= ?";
                    $params[] = (float)$amount_max;
                    $types .= 'd';
                }
            }
        }
        
        $donor_query .= " ORDER BY d.name ASC LIMIT 200";
        
        $stmt = $db->prepare($donor_query);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $donors = $result->fetch_all(MYSQLI_ASSOC);
    }
    
} catch (Exception $e) {
    $error_message = "Error loading donors: " . $e->getMessage();
    log_action("Error loading donors: " . $e->getMessage());
}

// Get unique cities for filter
$cities = [];
try {
    $cities_query = $db->query("SELECT DISTINCT city FROM donors WHERE city IS NOT NULL AND city != '' ORDER BY city ASC");
    while ($row = $cities_query->fetch_assoc()) {
        $cities[] = $row['city'];
    }
} catch (Exception $e) {
    // Ignore
}

// Fetch churches
$churches = [];
try {
    $churches_query = $db->query("SELECT id, name, city FROM churches WHERE is_active = 1 ORDER BY city, name");
    while ($row = $churches_query->fetch_assoc()) {
        $churches[] = $row;
    }
} catch (Exception $e) {
    log_action("Error loading churches: " . $e->getMessage());
}

// Get selected donor info if provided
$selected_donor = null;
$selected_donor_id = (int)($_GET['donor_id'] ?? 0);
$selected_church_id = (int)($_GET['church_id'] ?? 0);
$selected_rep_id = (int)($_GET['rep_id'] ?? 0);

if ($selected_donor_id > 0) {
    try {
        $stmt = $db->prepare("
            SELECT d.*, c.name as church_name, c.city as church_city 
            FROM donors d 
            LEFT JOIN churches c ON d.church_id = c.id 
            WHERE d.id = ?
        ");
        $stmt->bind_param("i", $selected_donor_id);
        $stmt->execute();
        $selected_donor = $stmt->get_result()->fetch_assoc();
    } catch (Exception $e) {
        // Ignore
    }
}

// Get representatives for selected church
$representatives = [];
if ($selected_church_id > 0) {
    try {
        $reps_stmt = $db->prepare("
            SELECT id, name, role, phone, email, is_primary 
            FROM church_representatives 
            WHERE church_id = ? AND is_active = 1 
            ORDER BY is_primary DESC, name ASC
        ");
        $reps_stmt->bind_param("i", $selected_church_id);
        $reps_stmt->execute();
        $reps_result = $reps_stmt->get_result();
        while ($row = $reps_result->fetch_assoc()) {
            $representatives[] = $row;
        }
    } catch (Exception $e) {
        // Ignore
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
            --assign-primary: #0a6286;
        }
        
        .page-header {
            background: linear-gradient(135deg, var(--assign-primary) 0%, #084767 100%);
            color: white;
            padding: 2rem 1.5rem;
            margin-bottom: 2rem;
            border-radius: 12px;
        }
        
        .step-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2rem;
            position: relative;
        }
        
        .step-indicator::before {
            content: '';
            position: absolute;
            top: 20px;
            left: 0;
            right: 0;
            height: 2px;
            background: #e2e8f0;
            z-index: 0;
        }
        
        .step {
            flex: 1;
            text-align: center;
            position: relative;
            z-index: 1;
        }
        
        .step-circle {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: white;
            border: 3px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.5rem;
            font-weight: 600;
            color: #64748b;
            transition: all 0.3s ease;
        }
        
        .step.active .step-circle {
            background: var(--assign-primary);
            border-color: var(--assign-primary);
            color: white;
        }
        
        .step.completed .step-circle {
            background: #10b981;
            border-color: #10b981;
            color: white;
        }
        
        .step-label {
            font-size: 0.875rem;
            color: #64748b;
            font-weight: 500;
        }
        
        .step.active .step-label {
            color: var(--assign-primary);
            font-weight: 600;
        }
        
        .step-content {
            display: none;
        }
        
        .step-content.active {
            display: block;
        }
        
        .form-card {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .donor-card {
            background: white;
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: 0.75rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            border-left: 4px solid #e2e8f0;
            transition: all 0.2s ease;
            cursor: pointer;
        }
        
        .donor-card:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        
        .donor-card.assigned {
            border-left-color: #10b981;
        }
        
        .donor-card.unassigned {
            border-left-color: #f59e0b;
        }
        
        .badge-assigned {
            background: #10b981;
            color: white;
            padding: 0.375rem 0.75rem;
            border-radius: 6px;
            font-size: 0.875rem;
        }
        
        .badge-unassigned {
            background: #f59e0b;
            color: white;
            padding: 0.375rem 0.75rem;
            border-radius: 6px;
            font-size: 0.875rem;
        }
        
        .selected-donor-card {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            border: 2px solid var(--assign-primary);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .church-card {
            background: white;
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: 0.75rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            border: 2px solid #e2e8f0;
            transition: all 0.2s ease;
            cursor: pointer;
        }
        
        .church-card:hover {
            border-color: var(--assign-primary);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .church-card.selected {
            border-color: var(--assign-primary);
            background: #f0f9ff;
        }
        
        .rep-card {
            background: white;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 0.75rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            border: 2px solid #e2e8f0;
            transition: all 0.2s ease;
            cursor: pointer;
        }
        
        .rep-card:hover {
            border-color: var(--assign-primary);
        }
        
        .rep-card.selected {
            border-color: var(--assign-primary);
            background: #f0f9ff;
            border-width: 3px;
        }
        
        .selected-church-card {
            background: #f0f9ff;
            border: 2px solid var(--assign-primary);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .btn-action {
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            border-radius: 8px;
        }
        
        @media (max-width: 768px) {
            .page-header {
                padding: 1.5rem 1rem;
            }
            
            .page-header h1 {
                font-size: 1.5rem;
            }
            
            .form-card {
                padding: 1.5rem;
            }
            
            .step-label {
                font-size: 0.75rem;
            }
            
            .step-circle {
                width: 35px;
                height: 35px;
                font-size: 0.875rem;
            }
            
            .donor-card, .church-card {
                padding: 1rem;
            }
            
            .btn-action {
                width: 100%;
                margin-bottom: 0.5rem;
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
                            <h1 class="mb-2"><i class="fas fa-user-tag me-2"></i>Assign Donors to Churches</h1>
                            <p class="mb-0 opacity-75">Simple step-by-step assignment process</p>
                        </div>
                        <a href="index.php" class="btn btn-light btn-lg mt-2 mt-md-0">
                            <i class="fas fa-arrow-left me-2"></i>Back
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
                
                <!-- Step Indicator -->
                <div class="step-indicator">
                    <div class="step <?php echo !$selected_donor_id ? 'active' : 'completed'; ?>" id="step1">
                        <div class="step-circle">1</div>
                        <div class="step-label">Find Donor</div>
                    </div>
                    <div class="step <?php echo $selected_donor_id && !$selected_church_id ? 'active' : ($selected_donor_id && $selected_church_id ? 'completed' : ''); ?>" id="step2">
                        <div class="step-circle">2</div>
                        <div class="step-label">Choose Church</div>
                    </div>
                    <div class="step <?php echo $selected_church_id && !$selected_rep_id ? 'active' : ($selected_church_id && $selected_rep_id ? 'completed' : ''); ?>" id="step3">
                        <div class="step-circle">3</div>
                        <div class="step-label">Select Representative</div>
                    </div>
                    <div class="step <?php echo $selected_rep_id ? 'active' : ''; ?>" id="step4">
                        <div class="step-circle">4</div>
                        <div class="step-label">Confirm</div>
                    </div>
                </div>
                
                <!-- Step 1: Find Donor -->
                <div class="step-content <?php echo !$selected_donor_id ? 'active' : ''; ?>" id="content1">
                    <div class="form-card">
                        <h5 class="mb-4"><i class="fas fa-search me-2"></i>Step 1: Search & Filter Donors</h5>
                        
                        <!-- Search & Filter -->
                        <form method="GET" action="" class="mb-4">
                            <div class="row g-3">
                                <!-- Search -->
                                <div class="col-md-12">
                                    <label class="form-label"><i class="fas fa-search me-1"></i>Search</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                                        <input type="text" name="search" class="form-control" 
                                               placeholder="Search by donor name or phone number..." 
                                               value="<?php echo htmlspecialchars($search); ?>">
                                    </div>
                                </div>
                                
                                <!-- Donor Type -->
                                <div class="col-md-6 col-lg-3">
                                    <label class="form-label">Donor Type</label>
                                    <select name="donor_type_filter" class="form-select">
                                        <option value="">All Types</option>
                                        <option value="pledge" <?php echo $donor_type_filter === 'pledge' ? 'selected' : ''; ?>>Pledge Donors</option>
                                        <option value="immediate_payment" <?php echo $donor_type_filter === 'immediate_payment' ? 'selected' : ''; ?>>Immediate Payers</option>
                                    </select>
                                </div>
                                
                                <!-- Payment Status -->
                                <div class="col-md-6 col-lg-3">
                                    <label class="form-label">Payment Status</label>
                                    <select name="payment_status_filter" class="form-select">
                                        <option value="">All Statuses</option>
                                        <option value="no_pledge" <?php echo $payment_status_filter === 'no_pledge' ? 'selected' : ''; ?>>No Pledge</option>
                                        <option value="not_started" <?php echo $payment_status_filter === 'not_started' ? 'selected' : ''; ?>>Not Started</option>
                                        <option value="paying" <?php echo $payment_status_filter === 'paying' ? 'selected' : ''; ?>>Paying</option>
                                        <option value="overdue" <?php echo $payment_status_filter === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                                        <option value="completed" <?php echo $payment_status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                        <option value="defaulted" <?php echo $payment_status_filter === 'defaulted' ? 'selected' : ''; ?>>Defaulted</option>
                                    </select>
                                </div>
                                
                                <!-- City -->
                                <div class="col-md-6 col-lg-3">
                                    <label class="form-label">City</label>
                                    <select name="city_filter" class="form-select">
                                        <option value="">All Cities</option>
                                        <?php foreach ($cities as $city): ?>
                                        <option value="<?php echo htmlspecialchars($city); ?>" 
                                                <?php echo $city_filter === $city ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($city); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <!-- Assignment Status -->
                                <div class="col-md-6 col-lg-3">
                                    <label class="form-label">Assignment</label>
                                    <select name="assignment_status_filter" class="form-select">
                                        <option value="">All</option>
                                        <option value="assigned" <?php echo $assignment_status_filter === 'assigned' ? 'selected' : ''; ?>>Assigned to Church</option>
                                        <option value="unassigned" <?php echo $assignment_status_filter === 'unassigned' ? 'selected' : ''; ?>>Not Assigned</option>
                                    </select>
                                </div>
                                
                                <!-- Amount Range -->
                                <div class="col-md-12">
                                    <label class="form-label"><i class="fas fa-pound-sign me-1"></i>Amount Filter</label>
                                    <div class="row g-2">
                                        <div class="col-md-3">
                                            <select name="amount_range_filter" class="form-select">
                                                <option value="">Select Amount Type</option>
                                                <option value="pledged" <?php echo $amount_range_filter === 'pledged' ? 'selected' : ''; ?>>Total Pledged</option>
                                                <option value="paid" <?php echo $amount_range_filter === 'paid' ? 'selected' : ''; ?>>Total Paid</option>
                                                <option value="balance" <?php echo $amount_range_filter === 'balance' ? 'selected' : ''; ?>>Balance</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <input type="number" name="amount_min" class="form-control" 
                                                   placeholder="Min amount (£)" 
                                                   step="0.01" min="0"
                                                   value="<?php echo htmlspecialchars($amount_min); ?>">
                                        </div>
                                        <div class="col-md-4">
                                            <input type="number" name="amount_max" class="form-control" 
                                                   placeholder="Max amount (£)" 
                                                   step="0.01" min="0"
                                                   value="<?php echo htmlspecialchars($amount_max); ?>">
                                        </div>
                                    </div>
                                    <small class="text-muted">Leave min/max empty to filter by any amount</small>
                                </div>
                                
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-filter me-2"></i>Search & Filter
                                    </button>
                                    <?php if ($has_filters): ?>
                                    <a href="assign-donors.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-times me-2"></i>Clear All Filters
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </form>
                        
                        <!-- Donors List -->
                        <?php if (!$has_filters): ?>
                            <!-- Empty State - No Filters Applied -->
                            <div class="text-center py-5">
                                <i class="fas fa-search fa-4x mb-4 text-muted opacity-25"></i>
                                <h5 class="mb-3">Search for a Donor</h5>
                                <p class="text-muted mb-4">
                                    Use the search and filters above to find donors.<br>
                                    <strong>No results will be shown until you apply at least one filter.</strong>
                                </p>
                                <div class="row g-3 justify-content-center">
                                    <div class="col-md-10">
                                        <div class="card border-0 bg-light">
                                            <div class="card-body p-4">
                                                <h6 class="mb-3"><i class="fas fa-lightbulb me-2 text-warning"></i>Filter Options:</h6>
                                                <div class="row g-3 text-start">
                                                    <div class="col-md-6">
                                                        <strong>Search:</strong>
                                                        <ul class="small mb-0">
                                                            <li>Donor name (e.g., "John")</li>
                                                            <li>Phone number (e.g., "07473")</li>
                                                        </ul>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <strong>Filters:</strong>
                                                        <ul class="small mb-0">
                                                            <li>Donor Type (Pledge/Immediate)</li>
                                                            <li>Payment Status</li>
                                                            <li>City</li>
                                                            <li>Assignment Status</li>
                                                            <li>Amount Range (Pledged/Paid/Balance)</li>
                                                        </ul>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php elseif (empty($donors)): ?>
                            <!-- No Results Found -->
                            <div class="text-center py-5 text-muted">
                                <i class="fas fa-users fa-3x mb-3 opacity-25"></i>
                                <h5 class="mb-2">No Donors Found</h5>
                                <p>Try adjusting your search or filters.</p>
                                <a href="assign-donors.php" class="btn btn-outline-secondary mt-3">
                                    <i class="fas fa-times me-2"></i>Clear All Filters
                                </a>
                            </div>
                        <?php else: ?>
                            <!-- Results Found -->
                            <div class="mb-3 d-flex justify-content-between align-items-center flex-wrap">
                                <div>
                                    <strong>Found <?php echo count($donors); ?> donor<?php echo count($donors) != 1 ? 's' : ''; ?></strong>
                                    <?php if (count($donors) >= 200): ?>
                                        <span class="badge bg-warning ms-2">Showing first 200 results</span>
                                    <?php endif; ?>
                                </div>
                                <a href="assign-donors.php" class="btn btn-sm btn-outline-secondary">
                                    <i class="fas fa-times me-1"></i>Clear Search
                                </a>
                            </div>
                            <?php foreach ($donors as $donor): ?>
                            <div class="donor-card <?php echo $donor['church_id'] ? 'assigned' : 'unassigned'; ?>" 
                                 onclick="selectDonor(<?php echo $donor['id']; ?>)">
                                <div class="d-flex justify-content-between align-items-center flex-wrap">
                                    <div class="flex-grow-1">
                                        <h6 class="mb-1"><?php echo htmlspecialchars($donor['name']); ?></h6>
                                        <p class="mb-1 small text-muted">
                                            <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($donor['phone']); ?>
                                            <?php if ($donor['city']): ?>
                                                <span class="ms-2"><i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($donor['city']); ?></span>
                                            <?php endif; ?>
                                        </p>
                                        <div class="mt-2 d-flex flex-wrap gap-2">
                                            <?php if ($donor['church_id']): ?>
                                                <span class="badge-assigned">
                                                    <i class="fas fa-church me-1"></i><?php echo htmlspecialchars($donor['church_name']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge-unassigned">
                                                    <i class="fas fa-exclamation-circle me-1"></i>Not Assigned
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($donor['donor_type']): ?>
                                                <span class="badge bg-info">
                                                    <?php echo ucfirst(str_replace('_', ' ', $donor['donor_type'])); ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($donor['payment_status']): ?>
                                                <span class="badge bg-secondary">
                                                    <?php echo ucfirst(str_replace('_', ' ', $donor['payment_status'])); ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if (isset($donor['total_pledged']) && (float)$donor['total_pledged'] > 0): ?>
                                                <span class="badge bg-primary">
                                                    <i class="fas fa-pound-sign me-1"></i>Pledged: £<?php echo number_format((float)$donor['total_pledged'], 2); ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if (isset($donor['balance']) && (float)$donor['balance'] > 0): ?>
                                                <span class="badge bg-warning">
                                                    <i class="fas fa-pound-sign me-1"></i>Balance: £<?php echo number_format((float)$donor['balance'], 2); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="ms-3 mt-2 mt-md-0">
                                        <button class="btn btn-primary btn-sm" onclick="event.stopPropagation(); selectDonor(<?php echo $donor['id']; ?>);">
                                            <i class="fas fa-arrow-right me-1"></i>Assign
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Step 2: Choose Church -->
                <?php if ($selected_donor && !$selected_church_id): ?>
                <div class="step-content active" id="content2">
                    <div class="selected-donor-card">
                        <h6 class="mb-2"><i class="fas fa-user me-2"></i>Selected Donor</h6>
                        <div class="d-flex justify-content-between align-items-center flex-wrap">
                            <div>
                                <strong><?php echo htmlspecialchars($selected_donor['name']); ?></strong>
                                <p class="mb-0 small text-muted">
                                    <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($selected_donor['phone']); ?>
                                </p>
                            </div>
                            <?php if ($selected_donor['church_id']): ?>
                                <div class="mt-2 mt-md-0">
                                    <span class="badge-assigned">
                                        Currently: <?php echo htmlspecialchars($selected_donor['church_name']); ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                            <div class="mt-2 mt-md-0">
                                <a href="assign-donors.php" class="btn btn-sm btn-outline-secondary">
                                    <i class="fas fa-times me-1"></i>Change
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-card">
                        <h5 class="mb-4"><i class="fas fa-church me-2"></i>Step 2: Choose Church</h5>
                        
                        <?php if (empty($churches)): ?>
                            <p class="text-muted">No churches available. Please add a church first.</p>
                        <?php else: ?>
                            <div class="row g-3">
                                <?php foreach ($churches as $church): ?>
                                <div class="col-md-6 col-lg-4">
                                    <div class="church-card" 
                                         onclick="selectChurch(<?php echo $church['id']; ?>)" 
                                         data-church-id="<?php echo $church['id']; ?>">
                                        <h6 class="mb-1"><?php echo htmlspecialchars($church['name']); ?></h6>
                                        <p class="mb-0 small text-muted">
                                            <i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($church['city']); ?>
                                        </p>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <div class="mt-4">
                                <a href="assign-donors.php" class="btn btn-outline-secondary btn-lg btn-action">
                                    <i class="fas fa-arrow-left me-2"></i>Back to Search
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Step 3: Select Representative -->
                <?php if ($selected_donor && $selected_church_id): ?>
                <div class="step-content active" id="content3">
                    <div class="selected-donor-card">
                        <h6 class="mb-2"><i class="fas fa-user me-2"></i>Selected Donor</h6>
                        <div class="d-flex justify-content-between align-items-center flex-wrap">
                            <div>
                                <strong><?php echo htmlspecialchars($selected_donor['name']); ?></strong>
                                <p class="mb-0 small text-muted">
                                    <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($selected_donor['phone']); ?>
                                </p>
                            </div>
                            <div class="mt-2 mt-md-0">
                                <a href="assign-donors.php?donor_id=<?php echo $selected_donor_id; ?>" class="btn btn-sm btn-outline-secondary">
                                    <i class="fas fa-times me-1"></i>Change Donor
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <?php
                    // Get selected church name
                    $selected_church_name = '';
                    foreach ($churches as $church) {
                        if ($church['id'] == $selected_church_id) {
                            $selected_church_name = $church['name'];
                            break;
                        }
                    }
                    ?>
                    
                    <div class="form-card mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-1"><i class="fas fa-church me-2"></i>Selected Church</h6>
                                <strong><?php echo htmlspecialchars($selected_church_name); ?></strong>
                            </div>
                            <a href="assign-donors.php?donor_id=<?php echo $selected_donor_id; ?>" class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-edit me-1"></i>Change Church
                            </a>
                        </div>
                    </div>
                    
                    <div class="form-card">
                        <h5 class="mb-4"><i class="fas fa-user-tie me-2"></i>Step 3: Select Representative <span class="text-danger">*</span></h5>
                        
                        <?php if (empty($representatives)): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                No active representatives found for this church. 
                                <a href="representatives.php?church_id=<?php echo $selected_church_id; ?>" class="alert-link">Add a representative first</a>.
                            </div>
                        <?php else: ?>
                            <form method="POST" action="" id="assignForm">
                                <input type="hidden" name="donor_id" value="<?php echo $selected_donor_id; ?>">
                                <input type="hidden" name="church_id" value="<?php echo $selected_church_id; ?>">
                                <input type="hidden" name="representative_id" id="selected_rep_id" value="<?php echo $selected_rep_id; ?>">
                                
                                <div class="row g-3">
                                    <?php foreach ($representatives as $rep): ?>
                                    <div class="col-md-6 col-lg-4">
                                        <div class="rep-card <?php echo $selected_rep_id == $rep['id'] ? 'selected' : ''; ?>" 
                                             onclick="selectRepresentative(<?php echo $rep['id']; ?>)" 
                                             data-rep-id="<?php echo $rep['id']; ?>">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div class="flex-grow-1">
                                                    <h6 class="mb-1">
                                                        <?php echo htmlspecialchars($rep['name']); ?>
                                                        <?php if ($rep['is_primary']): ?>
                                                            <span class="badge bg-success ms-2">Primary</span>
                                                        <?php endif; ?>
                                                    </h6>
                                                    <p class="mb-1 small text-muted">
                                                        <span class="badge bg-info"><?php echo htmlspecialchars($rep['role']); ?></span>
                                                    </p>
                                                    <?php if ($rep['phone']): ?>
                                                    <p class="mb-0 small text-muted">
                                                        <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($rep['phone']); ?>
                                                    </p>
                                                    <?php endif; ?>
                                                    <?php if ($rep['email']): ?>
                                                    <p class="mb-0 small text-muted">
                                                        <i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($rep['email']); ?>
                                                    </p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <div class="mt-4">
                                    <button type="submit" name="assign_donor" class="btn btn-primary btn-lg btn-action" id="assignBtn" <?php echo $selected_rep_id <= 0 ? 'disabled' : ''; ?>>
                                        <i class="fas fa-save me-2"></i>Complete Assignment
                                    </button>
                                    <a href="assign-donors.php?donor_id=<?php echo $selected_donor_id; ?>" class="btn btn-outline-secondary btn-lg btn-action">
                                        <i class="fas fa-arrow-left me-2"></i>Back to Church Selection
                                    </a>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
// Step 1: Select Donor
function selectDonor(donorId) {
    window.location.href = 'assign-donors.php?donor_id=' + donorId;
}

// Step 2: Select Church
function selectChurch(churchId) {
    const donorId = <?php echo $selected_donor_id; ?>;
    window.location.href = 'assign-donors.php?donor_id=' + donorId + '&church_id=' + churchId;
}

// Step 3: Select Representative
function selectRepresentative(repId) {
    document.getElementById('selected_rep_id').value = repId;
    
    // Update UI
    document.querySelectorAll('.rep-card').forEach(card => {
        card.classList.remove('selected');
    });
    event.currentTarget.classList.add('selected');
    
    // Enable assign button
    document.getElementById('assignBtn').disabled = false;
}

// Auto-select representative if provided in URL
<?php if ($selected_rep_id > 0): ?>
document.addEventListener('DOMContentLoaded', function() {
    const repId = <?php echo $selected_rep_id; ?>;
    document.getElementById('selected_rep_id').value = repId;
    document.getElementById('assignBtn').disabled = false;
    
    // Visual selection
    document.querySelectorAll('.rep-card').forEach(card => {
        if (card.getAttribute('data-rep-id') == repId) {
            card.classList.add('selected');
        }
    });
});
<?php endif; ?>
</script>
</body>
</html>
