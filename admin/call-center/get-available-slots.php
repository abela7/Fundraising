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
    $date = isset($_GET['date']) ? $_GET['date'] : '';
    $agent_id = isset($_GET['agent_id']) ? (int)$_GET['agent_id'] : 0;
    
    if (!$date || !$agent_id) {
        echo json_encode(['success' => false, 'message' => 'Missing parameters']);
        exit;
    }
    
    // Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        echo json_encode(['success' => false, 'message' => 'Invalid date format']);
        exit;
    }
    
    // Get day of week
    $day_of_week = strtolower(date('l', strtotime($date)));
    
    // Get agent's schedule for this day
    $schedule_query = "
        SELECT start_time, end_time 
        FROM call_center_agent_schedules 
        WHERE agent_id = ? 
          AND day_of_week = ? 
          AND is_active = 1
          AND (effective_from IS NULL OR effective_from <= ?)
          AND (effective_until IS NULL OR effective_until >= ?)
        LIMIT 1
    ";
    
    $stmt = $db->prepare($schedule_query);
    $stmt->bind_param('isss', $agent_id, $day_of_week, $date, $date);
    $stmt->execute();
    $result = $stmt->get_result();
    $schedule = $result->fetch_object();
    $stmt->close();
    
    if (!$schedule) {
        // No schedule for this day - use default hours
        $schedule = (object)[
            'start_time' => '09:00:00',
            'end_time' => '17:00:00'
        ];
    }
    
    // Get slot duration from config
    $config_query = "SELECT setting_value FROM call_center_appointment_config WHERE setting_key = 'default_slot_duration' LIMIT 1";
    $config_result = $db->query($config_query);
    $config_row = $config_result ? $config_result->fetch_assoc() : null;
    $slot_duration = $config_row ? (int)$config_row['setting_value'] : 30;
    
    // Get existing appointments for this agent on this date
    $appointments_query = "
        SELECT appointment_time 
        FROM call_center_appointments 
        WHERE agent_id = ? 
          AND appointment_date = ? 
          AND status IN ('scheduled', 'confirmed', 'in_progress')
    ";
    
    $stmt = $db->prepare($appointments_query);
    $stmt->bind_param('is', $agent_id, $date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $booked_slots = [];
    while ($row = $result->fetch_assoc()) {
        $booked_slots[] = $row['appointment_time'];
    }
    $stmt->close();
    
    // Check for time off
    $time_off_query = "
        SELECT COUNT(*) as has_time_off
        FROM call_center_agent_time_off 
        WHERE agent_id = ? 
          AND status = 'approved'
          AND (
              (all_day = 1 AND DATE(start_datetime) <= ? AND DATE(end_datetime) >= ?)
              OR
              (all_day = 0 AND DATE(start_datetime) = ? AND TIME(start_datetime) <= TIME(end_datetime))
          )
    ";
    
    $stmt = $db->prepare($time_off_query);
    $stmt->bind_param('isss', $agent_id, $date, $date, $date);
    $stmt->execute();
    $result = $stmt->get_result();
    $time_off_row = $result->fetch_assoc();
    $has_time_off = $time_off_row['has_time_off'] > 0;
    $stmt->close();
    
    if ($has_time_off) {
        echo json_encode([
            'success' => true,
            'slots' => [],
            'message' => 'Agent is not available on this date (time off)'
        ]);
        exit;
    }
    
    // Generate available slots
    $available_slots = [];
    $start = new DateTime($date . ' ' . $schedule->start_time);
    $end = new DateTime($date . ' ' . $schedule->end_time);
    
    while ($start < $end) {
        $slot_time = $start->format('H:i:s');
        
        // Check if this slot is not booked
        if (!in_array($slot_time, $booked_slots)) {
            $available_slots[] = [
                'time' => $slot_time,
                'formatted_time' => $start->format('g:i A')
            ];
        }
        
        // Move to next slot
        $start->add(new DateInterval('PT' . $slot_duration . 'M'));
    }
    
    // If date is today, filter out past slots
    if ($date === date('Y-m-d')) {
        $now = new DateTime();
        $now->add(new DateInterval('PT2H')); // Minimum 2 hours notice
        $min_time = $now->format('H:i:s');
        
        $available_slots = array_filter($available_slots, function($slot) use ($min_time) {
            return $slot['time'] >= $min_time;
        });
        
        // Re-index array
        $available_slots = array_values($available_slots);
    }
    
    echo json_encode([
        'success' => true,
        'slots' => $available_slots,
        'date' => $date,
        'day_of_week' => $day_of_week,
        'working_hours' => [
            'start' => $schedule->start_time,
            'end' => $schedule->end_time
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Get Available Slots Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while fetching available slots'
    ]);
}
?>

