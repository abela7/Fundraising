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
$agent_id = isset($_POST['agent_id']) && $_POST['agent_id'] !== '' ? (int)$_POST['agent_id'] : null;

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
    
    // Verify agent exists (if provided)
    $agent_name = null;
    if ($agent_id !== null && $agent_id > 0) {
        $agent_stmt = $db->prepare("SELECT id, name FROM users WHERE id = ? AND role IN ('admin', 'registrar')");
        $agent_stmt->bind_param("i", $agent_id);
        $agent_stmt->execute();
        $agent = $agent_stmt->get_result()->fetch_assoc();
        
        if (!$agent) {
            throw new Exception("Agent not found or is not authorized.");
        }
        $agent_name = $agent['name'];
    }
    
    // Check if columns exist
    $check_rep_column = $db->query("SHOW COLUMNS FROM donors LIKE 'representative_id'");
    $has_rep_column = $check_rep_column && $check_rep_column->num_rows > 0;
    
    $check_agent_column = $db->query("SHOW COLUMNS FROM donors LIKE 'agent_id'");
    $has_agent_column = $check_agent_column && $check_agent_column->num_rows > 0;
    
    // Build update query based on available columns
    if ($has_rep_column && $has_agent_column) {
        // Update church_id, representative_id, and agent_id
        $update_stmt = $db->prepare("UPDATE donors SET church_id = ?, representative_id = ?, agent_id = ? WHERE id = ?");
        $update_stmt->bind_param("iiii", $church_id, $representative_id, $agent_id, $donor_id);
    } elseif ($has_rep_column) {
        // Update church_id and representative_id only
        $update_stmt = $db->prepare("UPDATE donors SET church_id = ?, representative_id = ? WHERE id = ?");
        $update_stmt->bind_param("iii", $church_id, $representative_id, $donor_id);
    } else {
        // Only update church_id (old schema)
        $update_stmt = $db->prepare("UPDATE donors SET church_id = ? WHERE id = ?");
        $update_stmt->bind_param("ii", $church_id, $donor_id);
    }
    
    if (!$update_stmt->execute()) {
        throw new Exception("Failed to update assignment: " . $update_stmt->error);
    }
    
    // Build success message
    $success_message = 'Assignment updated successfully!';
    if ($agent_name) {
        $success_message .= ' Donor assigned to agent: ' . $agent_name;
    } elseif ($agent_id === null) {
        $success_message .= ' Agent assignment removed.';
    }
    
    header('Location: view-donor.php?id=' . $donor_id . '&success=' . urlencode($success_message));
    exit;
    
} catch (Exception $e) {
    header('Location: view-donor.php?id=' . $donor_id . '&error=' . urlencode($e->getMessage()));
    exit;
}

