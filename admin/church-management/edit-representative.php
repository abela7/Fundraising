<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

$db = db();
$page_title = 'Edit Representative';

$rep_id = (int)($_GET['id'] ?? 0);
$rep = null;
$errors = [];

// Fetch churches for dropdown
try {
    $churches_query = $db->query("SELECT id, name, city FROM churches WHERE is_active = 1 ORDER BY city, name");
    $churches = [];
    while ($row = $churches_query->fetch_assoc()) {
        $churches[] = $row;
    }
} catch (Exception $e) {
    $churches = [];
}

// Fetch representative data
if ($rep_id > 0) {
    try {
        $stmt = $db->prepare("SELECT * FROM church_representatives WHERE id = ?");
        $stmt->bind_param("i", $rep_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $rep = $result->fetch_assoc();
        
        if (!$rep) {
            header("Location: representatives.php?error=" . urlencode("Representative not found."));
            exit;
        }
    } catch (Exception $e) {
        $errors[] = "Error loading representative: " . $e->getMessage();
    }
} else {
    header("Location: representatives.php?error=" . urlencode("Invalid representative ID."));
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $role = trim($_POST['role'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $church_id = (int)($_POST['church_id'] ?? 0);
    $is_primary = isset($_POST['is_primary']) ? 1 : 0;
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $notes = trim($_POST['notes'] ?? '');
    
    if (empty($name)) {
        $errors[] = "Representative name is required.";
    }
    
    if (empty($role)) {
        $errors[] = "Role is required.";
    }
    
    if (empty($phone)) {
        $errors[] = "Phone number is required.";
    }
    
    if ($church_id <= 0) {
        $errors[] = "Please select a church.";
    }
    
    if (!empty($phone) && !preg_match('/^[\d\s\+\-\(\)]+$/', $phone)) {
        $errors[] = "Invalid phone number format.";
    }
    
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email address.";
    }
    
    // Check if setting as primary - ensure only one primary per church (excluding current rep)
    if ($is_primary && $church_id > 0) {
        try {
            $check_stmt = $db->prepare("SELECT id FROM church_representatives WHERE church_id = ? AND is_primary = 1 AND is_active = 1 AND id != ?");
            $check_stmt->bind_param("ii", $church_id, $rep_id);
            $check_stmt->execute();
            $existing_primary = $check_stmt->get_result()->fetch_assoc();
            if ($existing_primary) {
                $errors[] = "This church already has a primary representative. Please unset the existing primary first.";
            }
        } catch (Exception $e) {
            // Ignore check error, proceed
        }
    }
    
    if (empty($errors)) {
        try {
            $stmt = $db->prepare("
                UPDATE church_representatives 
                SET church_id = ?, name = ?, role = ?, phone = ?, email = ?, is_primary = ?, is_active = ?, notes = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->bind_param("issssiiisi", $church_id, $name, $role, $phone, $email, $is_primary, $is_active, $notes, $rep_id);
            
            if ($stmt->execute()) {
                header("Location: representatives.php?success=" . urlencode("Representative updated successfully!"));
                exit;
            } else {
                $errors[] = "Failed to update representative: " . $db->error;
            }
        } catch (Exception $e) {
            $errors[] = "Error: " . $e->getMessage();
        }
    }
    
    // Update local $rep for form display
    $rep['name'] = $name;
    $rep['role'] = $role;
    $rep['phone'] = $phone;
    $rep['email'] = $email;
    $rep['church_id'] = $church_id;
    $rep['is_primary'] = $is_primary;
    $rep['is_active'] = $is_active;
    $rep['notes'] = $notes;
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
        
        .form-card {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .form-label {
            font-weight: 600;
            color: #475569;
            margin-bottom: 0.5rem;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--rep-primary);
            box-shadow: 0 0 0 0.2rem rgba(10, 98, 134, 0.25);
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
                            <h1 class="mb-2"><i class="fas fa-edit me-2"></i>Edit Representative</h1>
                            <p class="mb-0 opacity-75">Update representative information</p>
                        </div>
                        <a href="representatives.php" class="btn btn-light btn-lg mt-2 mt-md-0">
                            <i class="fas fa-arrow-left me-2"></i>Back to List
                        </a>
                    </div>
                </div>
                
                <!-- Error Messages -->
                <?php if (!empty($errors)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <!-- Form -->
                <?php if ($rep): ?>
                <div class="form-card">
                    <form method="POST" action="">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="name" class="form-label">Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="name" name="name" 
                                       value="<?php echo htmlspecialchars($rep['name']); ?>" 
                                       required placeholder="e.g., Kesis Birhanu">
                            </div>
                            
                            <div class="col-md-6">
                                <label for="role" class="form-label">Role <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="role" name="role" 
                                       value="<?php echo htmlspecialchars($rep['role']); ?>" 
                                       required placeholder="e.g., Administrator, Secretary">
                            </div>
                            
                            <div class="col-md-6">
                                <label for="phone" class="form-label">Phone Number <span class="text-danger">*</span></label>
                                <input type="tel" class="form-control" id="phone" name="phone" 
                                       value="<?php echo htmlspecialchars($rep['phone']); ?>" 
                                       required placeholder="e.g., 07473 822244">
                            </div>
                            
                            <div class="col-md-6">
                                <label for="email" class="form-label">Email Address</label>
                                <input type="email" class="form-control" id="email" name="email" 
                                       value="<?php echo htmlspecialchars($rep['email'] ?? ''); ?>" 
                                       placeholder="e.g., representative@church.com">
                            </div>
                            
                            <div class="col-md-12">
                                <label for="church_id" class="form-label">Church <span class="text-danger">*</span></label>
                                <select class="form-select" id="church_id" name="church_id" required>
                                    <option value="">Select a church...</option>
                                    <?php foreach ($churches as $church): ?>
                                    <option value="<?php echo $church['id']; ?>" 
                                            <?php echo $rep['church_id'] == $church['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($church['city'] . ' - ' . $church['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-12">
                                <label for="notes" class="form-label">Notes</label>
                                <textarea class="form-control" id="notes" name="notes" rows="3" 
                                          placeholder="Additional notes about this representative"><?php echo htmlspecialchars($rep['notes'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="col-md-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="is_primary" name="is_primary" 
                                           <?php echo $rep['is_primary'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="is_primary">
                                        Primary Representative (Only one primary per church)
                                    </label>
                                </div>
                            </div>
                            
                            <div class="col-md-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="is_active" name="is_active" 
                                           <?php echo $rep['is_active'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="is_active">
                                        Active (Representative is currently active)
                                    </label>
                                </div>
                            </div>
                            
                            <div class="col-12">
                                <hr>
                                <div class="d-flex gap-2 justify-content-end">
                                    <a href="representatives.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-times me-2"></i>Cancel
                                    </a>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-2"></i>Update Representative
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
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

