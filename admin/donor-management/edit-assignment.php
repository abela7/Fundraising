<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

$db = db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action']) || $_POST['action'] !== 'update') {
    header('Location: donors.php?error=' . urlencode('Invalid request.'));
    exit;
}

$donor_id = (int)($_POST['donor_id'] ?? 0);
$church_id = (int)($_POST['church_id'] ?? 0);
$representative_id = (int)($_POST['representative_id'] ?? 0);

if ($donor_id <= 0) {
    header('Location: donors.php?error=' . urlencode('Invalid donor ID.'));
    exit;
}

if ($church_id <= 0) {
    header('Location: view-donor.php?id=' . $donor_id . '&error=' . urlencode('Please select a church.'));
    exit;
}

if ($representative_id <= 0) {
    header('Location: view-donor.php?id=' . $donor_id . '&error=' . urlencode('Please select a representative.'));
    exit;
}

try {
    // Verify donor exists
    $donor_stmt = $db->prepare("SELECT id, name FROM donors WHERE id = ?");
    $donor_stmt->bind_param("i", $donor_id);
    $donor_stmt->execute();
    $donor = $donor_stmt->get_result()->fetch_assoc();
    
    if (!$donor) {
        throw new Exception("Donor not found.");
    }
    
    // Verify church exists
    $church_stmt = $db->prepare("SELECT id, name FROM churches WHERE id = ?");
    $church_stmt->bind_param("i", $church_id);
    $church_stmt->execute();
    $church = $church_stmt->get_result()->fetch_assoc();
    
    if (!$church) {
        throw new Exception("Church not found.");
    }
    
    // Verify representative exists and belongs to the church
    $rep_stmt = $db->prepare("SELECT id, name FROM church_representatives WHERE id = ? AND church_id = ?");
    $rep_stmt->bind_param("ii", $representative_id, $church_id);
    $rep_stmt->execute();
    $rep = $rep_stmt->get_result()->fetch_assoc();
    
    if (!$rep) {
        throw new Exception("Representative not found or does not belong to the selected church.");
    }
    
    // Check if representative_id column exists
    $check_column = $db->query("SHOW COLUMNS FROM donors LIKE 'representative_id'");
    $has_rep_column = $check_column && $check_column->num_rows > 0;
    
    // Update assignment
    if ($has_rep_column) {
        // Update both church_id and representative_id
        $update_stmt = $db->prepare("UPDATE donors SET church_id = ?, representative_id = ? WHERE id = ?");
        $update_stmt->bind_param("iii", $church_id, $representative_id, $donor_id);
    } else {
        // Only update church_id (column doesn't exist yet)
        $update_stmt = $db->prepare("UPDATE donors SET church_id = ? WHERE id = ?");
        $update_stmt->bind_param("ii", $church_id, $donor_id);
    }
    
    if (!$update_stmt->execute()) {
        throw new Exception("Failed to update assignment: " . $update_stmt->error);
    }
    
    header('Location: view-donor.php?id=' . $donor_id . '&success=' . urlencode('Assignment updated successfully!'));
    exit;
    
} catch (Exception $e) {
    header('Location: view-donor.php?id=' . $donor_id . '&error=' . urlencode($e->getMessage()));
    exit;
}

