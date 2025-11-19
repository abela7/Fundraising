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
    
    log_action("Assignment attempt - Donor ID: {$donor_id}, Church ID: {$church_id}, Rep ID: {$representative_id}");
    
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
            
            $success_message = "Donor '{$current_donor['name']}' has been assigned to the selected church successfully!";
            log_action("Assignment completed successfully - Donor ID: {$donor_id}");
            
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

// Fetch donors
$donors = [];
$search = $_GET['search'] ?? '';
$church_filter = $_GET['church_filter'] ?? '';

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
    
    $donor_query .= " ORDER BY d.name ASC LIMIT 100";
    
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

// Fetch representatives for a church
function getRepresentatives($db, $church_id) {
    if ($church_id <= 0) return [];
    $stmt = $db->prepare("SELECT id, name, role, is_primary FROM church_representatives WHERE church_id = ? AND is_active = 1 ORDER BY is_primary DESC, name ASC");
    $stmt->bind_param("i", $church_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $reps = [];
    while ($row = $result->fetch_assoc()) {
        $reps[] = $row;
    }
    return $reps;
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
            padding: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            border-left: 4px solid #e2e8f0;
            transition: all 0.2s ease;
        }
        
        .donor-card.assigned {
            border-left-color: #10b981;
            background: #f0fdf4;
        }
        
        .donor-card:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .assignment-info {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
        }
        
        .form-label {
            font-weight: 600;
            color: #475569;
            margin-bottom: 0.5rem;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--assign-primary);
            box-shadow: 0 0 0 0.2rem rgba(10, 98, 134, 0.25);
        }
        
        .badge-assigned {
            background: #10b981;
            color: white;
            padding: 0.375rem 0.75rem;
            border-radius: 6px;
            font-size: 0.875rem;
        }
        
        .badge-unassigned {
            background: #64748b;
            color: white;
            padding: 0.375rem 0.75rem;
            border-radius: 6px;
            font-size: 0.875rem;
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
            
            .donor-card {
                padding: 1rem;
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
                            <p class="mb-0 opacity-75">Link donors to churches and representatives</p>
                        </div>
                        <a href="index.php" class="btn btn-light btn-lg mt-2 mt-md-0">
                            <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
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
                
                <!-- Assignment Form -->
                <div class="form-card">
                    <h5 class="mb-4"><i class="fas fa-link me-2"></i>Assign Donor to Church</h5>
                    <form method="POST" action="" id="assignForm">
                        <div class="row g-3">
                            <div class="col-md-12">
                                <label for="donor_id" class="form-label">Select Donor <span class="text-danger">*</span></label>
                                <select class="form-select" id="donor_id" name="donor_id" required>
                                    <option value="">-- Select a donor --</option>
                                    <?php foreach ($donors as $donor): ?>
                                    <option value="<?php echo $donor['id']; ?>" 
                                            data-current-church="<?php echo $donor['church_id'] ?? ''; ?>"
                                            data-current-church-name="<?php echo htmlspecialchars($donor['church_name'] ?? 'None'); ?>">
                                        <?php echo htmlspecialchars($donor['name']); ?> 
                                        (<?php echo htmlspecialchars($donor['phone']); ?>)
                                        <?php if ($donor['church_id']): ?>
                                            - Currently: <?php echo htmlspecialchars($donor['church_name']); ?>
                                        <?php endif; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <div id="currentAssignment" class="assignment-info mt-2" style="display: none;">
                                    <strong>Current Assignment:</strong>
                                    <div id="assignmentDetails"></div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="church_id" class="form-label">Select Church <span class="text-danger">*</span></label>
                                <select class="form-select" id="church_id" name="church_id" required>
                                    <option value="">-- Select a church --</option>
                                    <?php foreach ($churches as $church): ?>
                                    <option value="<?php echo $church['id']; ?>">
                                        <?php echo htmlspecialchars($church['city'] . ' - ' . $church['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="representative_id" class="form-label">Select Representative (Optional)</label>
                                <select class="form-select" id="representative_id" name="representative_id">
                                    <option value="0">-- Select a representative --</option>
                                </select>
                                <small class="text-muted">Select a church first to see representatives</small>
                            </div>
                            
                            <div class="col-12">
                                <hr>
                                <button type="submit" name="assign_donor" class="btn btn-primary btn-lg">
                                    <i class="fas fa-save me-2"></i>Assign Donor to Church
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
                
                <!-- Donors List -->
                <div class="form-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0"><i class="fas fa-users me-2"></i>Donors List</h5>
                        <form method="GET" action="" class="d-flex gap-2">
                            <input type="text" name="search" class="form-control form-control-sm" 
                                   placeholder="Search by name or phone..." 
                                   value="<?php echo htmlspecialchars($search); ?>">
                            <select name="church_filter" class="form-select form-select-sm">
                                <option value="">All Churches</option>
                                <?php foreach ($churches as $church): ?>
                                <option value="<?php echo $church['id']; ?>" 
                                        <?php echo $church_filter == $church['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($church['city'] . ' - ' . $church['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-search"></i>
                            </button>
                            <?php if (!empty($search) || !empty($church_filter)): ?>
                            <a href="assign-donors.php" class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-times"></i>
                            </a>
                            <?php endif; ?>
                        </form>
                    </div>
                    
                    <?php if (empty($donors)): ?>
                        <p class="text-muted text-center py-4">No donors found. Try adjusting your search.</p>
                    <?php else: ?>
                        <?php foreach ($donors as $donor): ?>
                        <div class="donor-card <?php echo $donor['church_id'] ? 'assigned' : ''; ?>">
                            <div class="d-flex justify-content-between align-items-start flex-wrap">
                                <div class="flex-grow-1">
                                    <h6 class="mb-1">
                                        <a href="../donor-management/view-donor.php?id=<?php echo $donor['id']; ?>" 
                                           class="text-decoration-none">
                                            <?php echo htmlspecialchars($donor['name']); ?>
                                        </a>
                                    </h6>
                                    <p class="mb-1 small text-muted">
                                        <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($donor['phone']); ?>
                                        <?php if ($donor['city']): ?>
                                            <span class="ms-2"><i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($donor['city']); ?></span>
                                        <?php endif; ?>
                                    </p>
                                    <?php if ($donor['church_id']): ?>
                                        <div class="mt-2">
                                            <span class="badge-assigned">
                                                <i class="fas fa-church me-1"></i>Assigned to: <?php echo htmlspecialchars($donor['church_name']); ?>
                                            </span>
                                            <?php if ($donor['rep_count'] > 0): ?>
                                                <span class="badge bg-info ms-2">
                                                    <?php echo $donor['rep_count']; ?> Representative<?php echo $donor['rep_count'] > 1 ? 's' : ''; ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="mt-2">
                                            <span class="badge-unassigned">
                                                <i class="fas fa-exclamation-circle me-1"></i>Not Assigned
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="mt-2 mt-md-0">
                                    <button type="button" class="btn btn-sm btn-primary" 
                                            onclick="assignDonor(<?php echo $donor['id']; ?>, '<?php echo htmlspecialchars($donor['name']); ?>', <?php echo $donor['church_id'] ?? 0; ?>)">
                                        <i class="fas fa-edit me-1"></i>Assign
                                    </button>
                                </div>
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
<script>
// Load representatives when church is selected
document.getElementById('church_id').addEventListener('change', function() {
    const churchId = this.value;
    const repSelect = document.getElementById('representative_id');
    
    repSelect.innerHTML = '<option value="0">-- Loading representatives --</option>';
    
    if (churchId) {
        fetch(`get-representatives.php?church_id=${churchId}`)
            .then(response => response.json())
            .then(data => {
                repSelect.innerHTML = '<option value="0">-- Select a representative (optional) --</option>';
                if (data.representatives && data.representatives.length > 0) {
                    data.representatives.forEach(rep => {
                        const option = document.createElement('option');
                        option.value = rep.id;
                        option.textContent = rep.name + (rep.is_primary ? ' (Primary)' : '') + ' - ' + rep.role;
                        repSelect.appendChild(option);
                    });
                } else {
                    repSelect.innerHTML = '<option value="0">No representatives available</option>';
                }
            })
            .catch(error => {
                console.error('Error loading representatives:', error);
                repSelect.innerHTML = '<option value="0">Error loading representatives</option>';
            });
    } else {
        repSelect.innerHTML = '<option value="0">-- Select a church first --</option>';
    }
});

// Show current assignment when donor is selected
document.getElementById('donor_id').addEventListener('change', function() {
    const selectedOption = this.options[this.selectedIndex];
    const currentChurchId = selectedOption.getAttribute('data-current-church');
    const currentChurchName = selectedOption.getAttribute('data-current-church-name');
    const assignmentDiv = document.getElementById('currentAssignment');
    const assignmentDetails = document.getElementById('assignmentDetails');
    
    if (currentChurchId && currentChurchId !== '') {
        assignmentDiv.style.display = 'block';
        assignmentDetails.innerHTML = `
            <div class="mt-2">
                <span class="badge-assigned">
                    <i class="fas fa-church me-1"></i>${currentChurchName}
                </span>
            </div>
            <p class="small text-muted mt-2 mb-0">
                <i class="fas fa-info-circle me-1"></i>This donor is already assigned. Selecting a new church will reassign them.
            </p>
        `;
    } else {
        assignmentDiv.style.display = 'none';
    }
});

// Quick assign function
function assignDonor(donorId, donorName, currentChurchId) {
    document.getElementById('donor_id').value = donorId;
    document.getElementById('donor_id').dispatchEvent(new Event('change'));
    document.getElementById('assignForm').scrollIntoView({ behavior: 'smooth', block: 'start' });
}
</script>
</body>
</html>

