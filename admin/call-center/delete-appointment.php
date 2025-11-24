<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';

require_login();

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: my-schedule.php');
    exit;
}

// Verify CSRF token
if (!verify_csrf()) {
    $_SESSION['error'] = 'Invalid security token. Please try again.';
    header('Location: my-schedule.php');
    exit;
}

try {
    $db = db();
    $user_id = (int)$_SESSION['user']['id'];
    $appointment_id = isset($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : 0;
    
    if (!$appointment_id) {
        throw new Exception('Invalid appointment ID.');
    }
    
    // First, verify that this appointment belongs to the logged-in user
    $verify_query = "SELECT id, status FROM call_center_appointments WHERE id = ? AND agent_id = ?";
    $stmt = $db->prepare($verify_query);
    $stmt->bind_param('ii', $appointment_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $appointment = $result->fetch_assoc();
    $stmt->close();
    
    if (!$appointment) {
        throw new Exception('Appointment not found or you do not have permission to delete it.');
    }
    
    // Check if appointment can be deleted (only scheduled or confirmed)
    if ($appointment['status'] !== 'scheduled' && $appointment['status'] !== 'confirmed') {
        throw new Exception('Only scheduled or confirmed appointments can be deleted.');
    }
    
    // Delete the appointment
    $delete_query = "DELETE FROM call_center_appointments WHERE id = ? AND agent_id = ?";
    $stmt = $db->prepare($delete_query);
    $stmt->bind_param('ii', $appointment_id, $user_id);
    
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            $_SESSION['success'] = 'Appointment deleted successfully.';
        } else {
            throw new Exception('Failed to delete appointment. Please try again.');
        }
    } else {
        throw new Exception('Database error: ' . $stmt->error);
    }
    
    $stmt->close();
    
} catch (Exception $e) {
    error_log("Delete Appointment Error: " . $e->getMessage());
    $_SESSION['error'] = $e->getMessage();
}

// Redirect back to schedule
header('Location: my-schedule.php');
exit;

