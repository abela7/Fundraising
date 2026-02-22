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
$church_id = isset($_POST['church_id']) && $_POST['church_id'] !== '' ? (int)$_POST['church_id'] : null;
$representative_id = isset($_POST['representative_id']) && $_POST['representative_id'] !== '' ? (int)$_POST['representative_id'] : null;
$agent_id = isset($_POST['agent_id']) && $_POST['agent_id'] !== '' ? (int)$_POST['agent_id'] : null;

if ($donor_id <= 0) {
    header('Location: donors.php?error=' . urlencode('Invalid donor ID.'));
    exit;
}

// Check if at least one field is being updated
if ($church_id === null && $representative_id === null && $agent_id === null) {
    header('Location: view-donor.php?id=' . $donor_id . '&error=' . urlencode('Please select at least one field to update.'));
    exit;
}

// If representative is selected, church must also be selected
if ($representative_id !== null && $representative_id > 0 && ($church_id === null || $church_id <= 0)) {
    header('Location: view-donor.php?id=' . $donor_id . '&error=' . urlencode('Please select a church when assigning a representative.'));
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
    
    $updated_fields = [];
    $church_name = null;
    $rep_name = null;
    $agent_name = null;
    
    // Verify church exists (if provided)
    if ($church_id !== null && $church_id > 0) {
        $church_stmt = $db->prepare("SELECT id, name FROM churches WHERE id = ?");
        $church_stmt->bind_param("i", $church_id);
        $church_stmt->execute();
        $church = $church_stmt->get_result()->fetch_assoc();
        
        if (!$church) {
            throw new Exception("Church not found.");
        }
        $church_name = $church['name'];
        $updated_fields[] = 'church';
    }
    
    // Verify representative exists and belongs to the church (if provided)
    if ($representative_id !== null && $representative_id > 0) {
        if ($church_id === null || $church_id <= 0) {
            throw new Exception("Representative requires a church to be selected.");
        }
        
        $rep_stmt = $db->prepare("SELECT id, name FROM church_representatives WHERE id = ? AND church_id = ?");
        $rep_stmt->bind_param("ii", $representative_id, $church_id);
        $rep_stmt->execute();
        $rep = $rep_stmt->get_result()->fetch_assoc();
        
        if (!$rep) {
            throw new Exception("Representative not found or does not belong to the selected church.");
        }
        $rep_name = $rep['name'];
        $updated_fields[] = 'representative';
    }
    
    // Verify agent exists (if provided)
    if ($agent_id !== null && $agent_id > 0) {
        $agent_stmt = $db->prepare("SELECT id, name FROM users WHERE id = ? AND role IN ('admin', 'registrar') AND active = 1");
        $agent_stmt->bind_param("i", $agent_id);
        $agent_stmt->execute();
        $agent = $agent_stmt->get_result()->fetch_assoc();
        
        if (!$agent) {
            throw new Exception("Agent not found or is not authorized.");
        }
        $agent_name = $agent['name'];
        $updated_fields[] = 'agent';
    }
    
    // Check if columns exist
    $check_rep_column = $db->query("SHOW COLUMNS FROM donors LIKE 'representative_id'");
    $has_rep_column = $check_rep_column && $check_rep_column->num_rows > 0;
    
    $check_agent_column = $db->query("SHOW COLUMNS FROM donors LIKE 'agent_id'");
    $has_agent_column = $check_agent_column && $check_agent_column->num_rows > 0;
    
    // Build dynamic UPDATE query
    $update_fields = [];
    $update_params = [];
    $param_types = '';
    
    if ($church_id !== null) {
        $update_fields[] = "church_id = ?";
        $update_params[] = $church_id;
        $param_types .= 'i';
    }
    
    if ($representative_id !== null && $has_rep_column) {
        $update_fields[] = "representative_id = ?";
        $update_params[] = $representative_id;
        $param_types .= 'i';
    }
    
    if ($agent_id !== null && $has_agent_column) {
        $update_fields[] = "agent_id = ?";
        $update_params[] = $agent_id;
        $param_types .= 'i';
    }
    
    // If no fields to update, throw error
    if (empty($update_fields)) {
        throw new Exception("No valid fields to update. Database schema may not support requested changes.");
    }
    
    // Add donor_id to params
    $update_params[] = $donor_id;
    $param_types .= 'i';
    
    // Build and execute query
    $sql = "UPDATE donors SET " . implode(", ", $update_fields) . " WHERE id = ?";
    $update_stmt = $db->prepare($sql);
    $update_stmt->bind_param($param_types, ...$update_params);
    
    if (!$update_stmt->execute()) {
        throw new Exception("Failed to update assignment: " . $update_stmt->error);
    }
    
    // Build success message
    $success_parts = [];
    if ($church_name) {
        $success_parts[] = "Church: {$church_name}";
    }
    if ($rep_name) {
        $success_parts[] = "Representative: {$rep_name}";
    }
    if ($agent_name) {
        $success_parts[] = "Agent: {$agent_name}";
    }
    
    $success_message = 'Assignment updated successfully!';
    if (!empty($success_parts)) {
        $success_message .= ' Updated: ' . implode(', ', $success_parts);
    }
    
    header('Location: view-donor.php?id=' . $donor_id . '&success=' . urlencode($success_message));
    exit;
    
} catch (Exception $e) {
    header('Location: view-donor.php?id=' . $donor_id . '&error=' . urlencode($e->getMessage()));
    exit;
}

