<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/audit_helper.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

$db = db();
$page_title = 'Edit Church';

$church_id = (int)($_GET['id'] ?? 0);
$church = null;
$errors = [];

// Fetch church data
if ($church_id > 0) {
    try {
        $stmt = $db->prepare("SELECT * FROM churches WHERE id = ?");
        $stmt->bind_param("i", $church_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $church = $result->fetch_assoc();
        
        if (!$church) {
            header("Location: churches.php?error=" . urlencode("Church not found."));
            exit;
        }
    } catch (Exception $e) {
        $errors[] = "Error loading church: " . $e->getMessage();
    }
} else {
    header("Location: churches.php?error=" . urlencode("Invalid church ID."));
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    if (empty($name)) {
        $errors[] = "Church name is required.";
    }
    
    if (empty($city)) {
        $errors[] = "City is required.";
    }
    
    if (!empty($phone) && !preg_match('/^[\d\s\+\-\(\)]+$/', $phone)) {
        $errors[] = "Invalid phone number format.";
    }
    
    if (empty($errors)) {
        try {
            $stmt = $db->prepare("
                UPDATE churches 
                SET name = ?, city = ?, address = ?, phone = ?, is_active = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->bind_param("ssssii", $name, $city, $address, $phone, $is_active, $church_id);
            
            if ($stmt->execute()) {
                // Audit log
                log_audit(
                    $db,
                    'update',
                    'church',
                    $church_id,
                    ['name' => $church['name'], 'city' => $church['city'], 'address' => $church['address'], 'phone' => $church['phone'], 'is_active' => $church['is_active']],
                    ['name' => $name, 'city' => $city, 'address' => $address, 'phone' => $phone, 'is_active' => $is_active],
                    'admin_portal',
                    (int)($_SESSION['user']['id'] ?? 0)
                );
                
                header("Location: churches.php?success=" . urlencode("Church updated successfully!"));
                exit;
            } else {
                $errors[] = "Failed to update church: " . $db->error;
            }
        } catch (Exception $e) {
            $errors[] = "Error: " . $e->getMessage();
        }
    }
    
    // Update local $church for form display
    $church['name'] = $name;
    $church['city'] = $city;
    $church['address'] = $address;
    $church['phone'] = $phone;
    $church['is_active'] = $is_active;
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
        }
        
        .page-header {
            background: linear-gradient(135deg, var(--church-primary) 0%, #084767 100%);
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
            border-color: var(--church-primary);
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
                            <h1 class="mb-2"><i class="fas fa-edit me-2"></i>Edit Church</h1>
                            <p class="mb-0 opacity-75">Update church information</p>
                        </div>
                        <a href="churches.php" class="btn btn-light btn-lg mt-2 mt-md-0">
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
                <?php if ($church): ?>
                <div class="form-card">
                    <form method="POST" action="">
                        <div class="row g-3">
                            <div class="col-md-12">
                                <label for="name" class="form-label">Church Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="name" name="name" 
                                       value="<?php echo htmlspecialchars($church['name']); ?>" 
                                       required placeholder="e.g., Mekane Kidusan Abune Tekle Haymanot Church">
                            </div>
                            
                            <div class="col-md-6">
                                <label for="city" class="form-label">City <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="city" name="city" 
                                       value="<?php echo htmlspecialchars($church['city']); ?>" 
                                       required placeholder="e.g., Liverpool">
                            </div>
                            
                            <div class="col-md-6">
                                <label for="phone" class="form-label">Phone Number</label>
                                <input type="tel" class="form-control" id="phone" name="phone" 
                                       value="<?php echo htmlspecialchars($church['phone'] ?? ''); ?>" 
                                       placeholder="e.g., 07473 822244">
                            </div>
                            
                            <div class="col-md-12">
                                <label for="address" class="form-label">Address</label>
                                <textarea class="form-control" id="address" name="address" rows="3" 
                                          placeholder="Full address of the church"><?php echo htmlspecialchars($church['address'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="col-md-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="is_active" name="is_active" 
                                           <?php echo $church['is_active'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="is_active">
                                        Active (Church is currently operational)
                                    </label>
                                </div>
                            </div>
                            
                            <div class="col-12">
                                <hr>
                                <div class="d-flex gap-2 justify-content-end">
                                    <a href="churches.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-times me-2"></i>Cancel
                                    </a>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-2"></i>Update Church
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

