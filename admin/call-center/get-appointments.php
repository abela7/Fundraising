<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

// Set timezone to London
date_default_timezone_set('Europe/London');

header('Content-Type: application/json');

try {
    $db = db();
    
    // Get parameters
    $agent_id = isset($_GET['agent_id']) ? (int)$_GET['agent_id'] : 0;
    $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
    $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;
    
    if (!$agent_id) {
        echo json_encode(['success' => false, 'message' => 'Missing agent ID']);
        exit;
    }
    
    // Build query
    $query = "
        SELECT 
            a.id,
            a.appointment_date,
            a.appointment_time,
            a.slot_duration_minutes,
            a.status,
            a.appointment_type,
            a.priority,
            a.notes,
            d.name as donor_name,
            d.phone as donor_phone,
            d.city as donor_city
        FROM call_center_appointments a
        JOIN donors d ON a.donor_id = d.id
        WHERE a.agent_id = ?
    ";
    
    $params = [$agent_id];
    $types = 'i';
    
    // Add date filters if provided
    if ($start_date) {
        $query .= " AND a.appointment_date >= ?";
        $params[] = $start_date;
        $types .= 's';
    }
    
    if ($end_date) {
        $query .= " AND a.appointment_date <= ?";
        $params[] = $end_date;
        $types .= 's';
    }
    
    $query .= " ORDER BY a.appointment_date, a.appointment_time";
    
    $stmt = $db->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $appointments = [];
    while ($row = $result->fetch_assoc()) {
        $appointments[] = $row;
    }
    
    $stmt->close();
    
    // Get statistics
    $stats_query = "
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as scheduled,
            SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
        FROM call_center_appointments
        WHERE agent_id = ?
          AND appointment_date >= CURDATE()
    ";
    
    $stmt = $db->prepare($stats_query);
    $stmt->bind_param('i', $agent_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats = $result->fetch_assoc();
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'appointments' => $appointments,
        'stats' => $stats,
        'count' => count($appointments)
    ]);
    
} catch (Exception $e) {
    error_log("Get Appointments Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while fetching appointments'
    ]);
}
?>

