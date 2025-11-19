<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

$db = db();

// Prioritize POST over GET for confirmation (POST is used when form is submitted)
$donor_id = (int)($_POST['donor_id'] ?? $_GET['donor_id'] ?? 0);
$confirm = $_POST['confirm'] ?? $_GET['confirm'] ?? 'no';

if ($donor_id <= 0) {
    header('Location: donors.php?error=' . urlencode('Invalid donor ID.'));
    exit;
}

try {
    // Get donor info
    $donor_stmt = $db->prepare("SELECT id, name, church_id, representative_id FROM donors WHERE id = ?");
    $donor_stmt->bind_param("i", $donor_id);
    $donor_stmt->execute();
    $donor = $donor_stmt->get_result()->fetch_assoc();
    
    if (!$donor) {
        throw new Exception("Donor not found.");
    }
    
    // Check if representative_id column exists
    $check_column = $db->query("SHOW COLUMNS FROM donors LIKE 'representative_id'");
    $has_rep_column = $check_column && $check_column->num_rows > 0;
    
    // If not confirmed, show confirmation page
    if ($confirm !== 'yes') {
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Remove Assignment</title>
            <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
            <link rel="stylesheet" href="../assets/admin.css">
        </head>
        <body>
            <div class="container mt-5">
                <div class="row justify-content-center">
                    <div class="col-md-6">
                        <div class="card shadow">
                            <div class="card-header bg-danger text-white">
                                <h5 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Confirm Removal</h5>
                            </div>
                            <div class="card-body">
                                <p class="mb-3">Are you sure you want to remove the assignment for <strong><?php echo htmlspecialchars($donor['name']); ?></strong>?</p>
                                
                                <?php
                                // Get current assignment info
                                $church_name = 'None';
                                $rep_name = 'None';
                                
                                if (!empty($donor['church_id'])) {
                                    $church_stmt = $db->prepare("SELECT name FROM churches WHERE id = ?");
                                    $church_stmt->bind_param("i", $donor['church_id']);
                                    $church_stmt->execute();
                                    $church_result = $church_stmt->get_result()->fetch_assoc();
                                    if ($church_result) {
                                        $church_name = $church_result['name'];
                                    }
                                }
                                
                                if ($has_rep_column && !empty($donor['representative_id'])) {
                                    $rep_stmt = $db->prepare("SELECT name FROM church_representatives WHERE id = ?");
                                    $rep_stmt->bind_param("i", $donor['representative_id']);
                                    $rep_stmt->execute();
                                    $rep_result = $rep_stmt->get_result()->fetch_assoc();
                                    if ($rep_result) {
                                        $rep_name = $rep_result['name'];
                                    }
                                }
                                ?>
                                
                                <div class="alert alert-warning">
                                    <strong>Current Assignment:</strong><br>
                                    Church: <?php echo htmlspecialchars($church_name); ?><br>
                                    Representative: <?php echo htmlspecialchars($rep_name); ?>
                                </div>
                                
                                <p class="text-muted small">This will remove the donor's assignment to the church and representative. The donor will remain in the system but will be unassigned.</p>
                                
                                <form method="POST" action="">
                                    <input type="hidden" name="donor_id" value="<?php echo $donor_id; ?>">
                                    <input type="hidden" name="confirm" value="yes">
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-danger">
                                            <i class="fas fa-trash-alt me-1"></i>Yes, Remove Assignment
                                        </button>
                                        <a href="view-donor.php?id=<?php echo $donor_id; ?>" class="btn btn-secondary">
                                            Cancel
                                        </a>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
    
    // Confirmed - proceed with deletion
    $db->begin_transaction();
    
    try {
        // Remove assignment
        if ($has_rep_column) {
            // Set both to NULL
            $update_stmt = $db->prepare("UPDATE donors SET church_id = NULL, representative_id = NULL WHERE id = ?");
        } else {
            // Only set church_id to NULL
            $update_stmt = $db->prepare("UPDATE donors SET church_id = NULL WHERE id = ?");
        }
        $update_stmt->bind_param("i", $donor_id);
        
        if (!$update_stmt->execute()) {
            throw new Exception("Failed to remove assignment: " . $update_stmt->error);
        }
        
        $db->commit();
        
        header('Location: view-donor.php?id=' . $donor_id . '&success=' . urlencode('Assignment removed successfully!'));
        exit;
        
    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    header('Location: view-donor.php?id=' . $donor_id . '&error=' . urlencode($e->getMessage()));
    exit;
}

