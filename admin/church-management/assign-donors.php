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
    
    log_action("Assignment attempt - Donor ID: {$donor_id}, Church ID: {$church_id}");
    
    $errors = [];
    
    if ($donor_id <= 0) {
        $errors[] = "Please select a donor.";
    }
    
    if ($church_id <= 0) {
        $errors[] = "Please select a church.";
    }
    
    if (empty($errors)) {
        try {
            $db->begin_transaction();
            
            // Get current donor info
            $donor_stmt = $db->prepare("SELECT id, name, phone, church_id FROM donors WHERE id = ?");
            $donor_stmt->bind_param("i", $donor_id);
            $donor_stmt->execute();
            $current_donor = $donor_stmt->get_result()->fetch_assoc();
            
            if (!$current_donor) {
                throw new Exception("Donor not found.");
            }
            
            $old_church_id = $current_donor['church_id'];
            
            log_action("Donor found - Name: {$current_donor['name']}, Current Church ID: " . ($old_church_id ?? 'NULL'));
            
            // Update donor's church_id
            $update_stmt = $db->prepare("UPDATE donors SET church_id = ? WHERE id = ?");
            $update_stmt->bind_param("ii", $church_id, $donor_id);
            
            if (!$update_stmt->execute()) {
                throw new Exception("Failed to update donor: " . $update_stmt->error);
            }
            
            log_action("Donor updated successfully - Donor ID: {$donor_id}, Old Church: " . ($old_church_id ?? 'NULL') . ", New Church: {$church_id}");
            
            $db->commit();
            
            $success_message = "Donor '{$current_donor['name']}' has been assigned successfully!";
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
$church_filter = $_GET['church_filter'] ?? '';
$status_filter = $_GET['status_filter'] ?? 'all';

try {
    $donor_query = "
        SELECT 
            d.id,
            d.name,
            d.phone,
            d.city,
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
    
    if (!empty($church_filter)) {
        $donor_query .= " AND d.church_id = ?";
        $params[] = $church_filter;
        $types .= 'i';
    }
    
    if ($status_filter === 'assigned') {
        $donor_query .= " AND d.church_id IS NOT NULL";
    } elseif ($status_filter === 'unassigned') {
        $donor_query .= " AND d.church_id IS NULL";
    }
    
    $donor_query .= " ORDER BY d.name ASC LIMIT 200";
    
    $stmt = $db->prepare($donor_query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $donors = $result->fetch_all(MYSQLI_ASSOC);
    
} catch (Exception $e) {
    $error_message = "Error loading donors: " . $e->getMessage();
    log_action("Error loading donors: " . $e->getMessage());
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
                    <div class="step <?php echo $selected_donor_id && !isset($_GET['church_id']) ? 'active' : ($selected_donor_id && isset($_GET['church_id']) ? 'completed' : ''); ?>" id="step2">
                        <div class="step-circle">2</div>
                        <div class="step-label">Choose Church</div>
                    </div>
                    <div class="step <?php echo isset($_GET['church_id']) ? 'active' : ''; ?>" id="step3">
                        <div class="step-circle">3</div>
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
                                <div class="col-md-6">
                                    <label class="form-label">Search</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                                        <input type="text" name="search" class="form-control" 
                                               placeholder="Search by name or phone..." 
                                               value="<?php echo htmlspecialchars($search); ?>">
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Filter by Church</label>
                                    <select name="church_filter" class="form-select">
                                        <option value="">All Churches</option>
                                        <?php foreach ($churches as $church): ?>
                                        <option value="<?php echo $church['id']; ?>" 
                                                <?php echo $church_filter == $church['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($church['city']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Status</label>
                                    <select name="status_filter" class="form-select">
                                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All</option>
                                        <option value="assigned" <?php echo $status_filter === 'assigned' ? 'selected' : ''; ?>>Assigned</option>
                                        <option value="unassigned" <?php echo $status_filter === 'unassigned' ? 'selected' : ''; ?>>Unassigned</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-filter me-2"></i>Apply Filters
                                    </button>
                                    <?php if (!empty($search) || !empty($church_filter) || $status_filter !== 'all'): ?>
                                    <a href="assign-donors.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-times me-2"></i>Clear
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </form>
                        
                        <!-- Donors List -->
                        <?php if (empty($donors)): ?>
                            <div class="text-center py-5 text-muted">
                                <i class="fas fa-users fa-3x mb-3 opacity-25"></i>
                                <p>No donors found. Try adjusting your search or filters.</p>
                            </div>
                        <?php else: ?>
                            <div class="mb-3">
                                <strong>Found <?php echo count($donors); ?> donor<?php echo count($donors) != 1 ? 's' : ''; ?></strong>
                            </div>
                            <?php foreach ($donors as $donor): ?>
                            <div class="donor-card <?php echo $donor['church_id'] ? 'assigned' : 'unassigned'; ?>" 
                                 onclick="selectDonor(<?php echo $donor['id']; ?>)">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="flex-grow-1">
                                        <h6 class="mb-1"><?php echo htmlspecialchars($donor['name']); ?></h6>
                                        <p class="mb-1 small text-muted">
                                            <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($donor['phone']); ?>
                                            <?php if ($donor['city']): ?>
                                                <span class="ms-2"><i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($donor['city']); ?></span>
                                            <?php endif; ?>
                                        </p>
                                        <?php if ($donor['church_id']): ?>
                                            <span class="badge-assigned">
                                                <i class="fas fa-church me-1"></i><?php echo htmlspecialchars($donor['church_name']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge-unassigned">
                                                <i class="fas fa-exclamation-circle me-1"></i>Not Assigned
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="ms-3">
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
                <?php if ($selected_donor): ?>
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
                            <form method="POST" action="" id="assignForm">
                                <input type="hidden" name="donor_id" value="<?php echo $selected_donor_id; ?>">
                                <input type="hidden" name="church_id" id="selected_church_id" value="<?php echo $_GET['church_id'] ?? ''; ?>">
                                
                                <div class="row g-3">
                                    <?php foreach ($churches as $church): ?>
                                    <div class="col-md-6 col-lg-4">
                                        <div class="church-card <?php echo (isset($_GET['church_id']) && $_GET['church_id'] == $church['id']) ? 'selected' : ''; ?>" 
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
                                
                                <div id="representativesSection" style="display: none;" class="mt-4">
                                    <h6 class="mb-3"><i class="fas fa-user-tie me-2"></i>Representatives (Optional)</h6>
                                    <div id="representativesList"></div>
                                </div>
                                
                                <div class="mt-4">
                                    <button type="submit" name="assign_donor" class="btn btn-primary btn-lg btn-action" id="assignBtn" disabled>
                                        <i class="fas fa-save me-2"></i>Complete Assignment
                                    </button>
                                    <a href="assign-donors.php" class="btn btn-outline-secondary btn-lg btn-action">
                                        <i class="fas fa-arrow-left me-2"></i>Back to Search
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
    document.getElementById('selected_church_id').value = churchId;
    
    // Update UI
    document.querySelectorAll('.church-card').forEach(card => {
        card.classList.remove('selected');
    });
    event.currentTarget.classList.add('selected');
    
    // Enable assign button
    document.getElementById('assignBtn').disabled = false;
    
    // Load representatives
    fetch(`get-representatives.php?church_id=${churchId}`)
        .then(response => response.json())
        .then(data => {
            const repsSection = document.getElementById('representativesSection');
            const repsList = document.getElementById('representativesList');
            
            if (data.representatives && data.representatives.length > 0) {
                repsList.innerHTML = '';
                data.representatives.forEach(rep => {
                    const repCard = document.createElement('div');
                    repCard.className = 'rep-card';
                    repCard.innerHTML = `
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <strong>${rep.name}</strong>
                                ${rep.is_primary ? '<span class="badge bg-success ms-2">Primary</span>' : ''}
                                <p class="mb-0 small text-muted">${rep.role}</p>
                            </div>
                        </div>
                    `;
                    repsList.appendChild(repCard);
                });
                repsSection.style.display = 'block';
            } else {
                repsSection.style.display = 'none';
            }
        })
        .catch(error => {
            console.error('Error loading representatives:', error);
        });
}

// Auto-select church if provided in URL
<?php if (isset($_GET['church_id']) && $selected_donor): ?>
document.addEventListener('DOMContentLoaded', function() {
    const churchId = <?php echo (int)$_GET['church_id']; ?>;
    document.getElementById('selected_church_id').value = churchId;
    document.getElementById('assignBtn').disabled = false;
    
    // Visual selection
    document.querySelectorAll('.church-card').forEach(card => {
        if (card.getAttribute('data-church-id') == churchId) {
            card.classList.add('selected');
        }
    });
    
    // Load representatives
    fetch(`get-representatives.php?church_id=${churchId}`)
        .then(response => response.json())
        .then(data => {
            const repsSection = document.getElementById('representativesSection');
            const repsList = document.getElementById('representativesList');
            
            if (data.representatives && data.representatives.length > 0) {
                repsList.innerHTML = '';
                data.representatives.forEach(rep => {
                    const repCard = document.createElement('div');
                    repCard.className = 'rep-card';
                    repCard.innerHTML = `
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <strong>${rep.name}</strong>
                                ${rep.is_primary ? '<span class="badge bg-success ms-2">Primary</span>' : ''}
                                <p class="mb-0 small text-muted">${rep.role}</p>
                            </div>
                        </div>
                    `;
                    repsList.appendChild(repCard);
                });
                repsSection.style.display = 'block';
            } else {
                repsSection.style.display = 'none';
            }
        })
        .catch(error => {
            console.error('Error loading representatives:', error);
        });
});
<?php endif; ?>
</script>
</body>
</html>
