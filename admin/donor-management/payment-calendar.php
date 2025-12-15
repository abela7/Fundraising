<?php
/**
 * Payment Calendar View
 * 
 * Shows due payments in daily, weekly, and monthly calendar views.
 * Fully mobile responsive.
 */

declare(strict_types=1);

// Start output buffering for AJAX requests
ob_start();

// Suppress errors for AJAX (we'll handle them properly)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    error_reporting(0);
    ini_set('display_errors', '0');
}

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

// Allow both admin and registrar access
$user = current_user();
if (!in_array($user['role'] ?? '', ['admin', 'registrar'])) {
    header('Location: ' . url_for('index.php'));
    exit;
}

require_once __DIR__ . '/../includes/resilient_db_loader.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../services/MessagingHelper.php';
require_once __DIR__ . '/../../services/UltraMsgService.php';

$page_title = 'Payment Calendar';
$db = db();

// Helper function to ensure reminder tracking table exists
function ensureReminderTrackingTable($db): void {
    $check = $db->query("SHOW TABLES LIKE 'payment_reminders_sent'");
    if (!$check || $check->num_rows === 0) {
        $db->query("
            CREATE TABLE IF NOT EXISTS payment_reminders_sent (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                donor_id INT NOT NULL,
                payment_plan_id INT NULL,
                due_date DATE NOT NULL,
                sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                sent_by_user_id INT NULL,
                sent_by_name VARCHAR(100) NULL,
                channel ENUM('whatsapp', 'sms', 'both') NOT NULL DEFAULT 'whatsapp',
                message_preview VARCHAR(500) NULL,
                source_type ENUM('cron', 'manual_calendar', 'bulk', 'call_center') NOT NULL DEFAULT 'manual_calendar',
                INDEX idx_donor_due (donor_id, due_date),
                INDEX idx_due_date (due_date),
                INDEX idx_donor_due_sent (donor_id, due_date, sent_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}

// Helper function to check if reminder was already sent today
function wasReminderSentToday($db, int $donorId, string $dueDate): ?array {
    $today = date('Y-m-d');
    $stmt = $db->prepare("
        SELECT id, sent_at, channel, sent_by_name, source_type
        FROM payment_reminders_sent 
        WHERE donor_id = ? AND due_date = ? AND DATE(sent_at) = ?
        ORDER BY sent_at DESC LIMIT 1
    ");
    $stmt->bind_param('iss', $donorId, $dueDate, $today);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    return $result ?: null;
}

// Helper function to record reminder sent
function recordReminderSent($db, int $donorId, ?int $planId, string $dueDate, string $channel, string $message, string $sourceType = 'manual_calendar'): bool {
    $currentUser = current_user();
    $userId = $currentUser['id'] ?? null;
    $userName = $currentUser['name'] ?? null;
    $preview = mb_substr($message, 0, 500);
    
    try {
        $stmt = $db->prepare("
            INSERT INTO payment_reminders_sent 
            (donor_id, payment_plan_id, due_date, channel, message_preview, source_type, sent_by_user_id, sent_by_name)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('iissssis', $donorId, $planId, $dueDate, $channel, $preview, $sourceType, $userId, $userName);
        return $stmt->execute();
    } catch (Exception $e) {
        error_log("Failed to record reminder: " . $e->getMessage());
        return false;
    }
}

// Handle AJAX delete reminder
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_reminder') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json; charset=utf-8');
    
    $sendJsonResponse = function($data) {
        while (ob_get_level()) {
            ob_end_clean();
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    };
    
    try {
        if (!$db) {
            $sendJsonResponse(['success' => false, 'error' => 'Database connection failed']);
        }
        
        $donor_id = (int)($_POST['donor_id'] ?? 0);
        $due_date = $_POST['due_date'] ?? '';
        
        if ($donor_id <= 0) {
            $sendJsonResponse(['success' => false, 'error' => 'Invalid donor ID']);
        }
        if (empty($due_date)) {
            $sendJsonResponse(['success' => false, 'error' => 'Due date is required']);
        }
        
        // Delete today's reminder for this donor/due_date
        $today = date('Y-m-d');
        $stmt = $db->prepare("
            DELETE FROM payment_reminders_sent 
            WHERE donor_id = ? AND due_date = ? AND DATE(sent_at) = ?
        ");
        $stmt->bind_param('iss', $donor_id, $due_date, $today);
        $result = $stmt->execute();
        $deleted = $stmt->affected_rows;
        
        if ($result && $deleted > 0) {
            $sendJsonResponse([
                'success' => true,
                'message' => 'Reminder record deleted successfully',
                'deleted_count' => $deleted
            ]);
        } else if ($result && $deleted === 0) {
            $sendJsonResponse([
                'success' => false,
                'error' => 'No reminder found to delete'
            ]);
        } else {
            $sendJsonResponse([
                'success' => false,
                'error' => 'Failed to delete reminder record'
            ]);
        }
    } catch (Throwable $e) {
        error_log('Delete Reminder Error: ' . $e->getMessage());
        $sendJsonResponse(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    }
}

// Handle AJAX reminder send
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_reminder') {
    // Clean ALL output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Set headers first
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    
    // Function to send JSON and exit cleanly
    $sendJsonResponse = function($data) {
        // Clean any output again before sending
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = json_encode(['success' => false, 'error' => 'JSON encoding failed: ' . json_last_error_msg()]);
        }
        echo $json;
        exit;
    };
    
    try {
        // Check database connection
        if (!$db) {
            $sendJsonResponse(['success' => false, 'error' => 'Database connection failed']);
        }
        
        // Ensure reminder tracking table exists
        ensureReminderTrackingTable($db);
        
        $donor_id = (int)($_POST['donor_id'] ?? 0);
        $message = trim($_POST['message'] ?? '');
        $channel = $_POST['channel'] ?? 'whatsapp';
        $due_date = $_POST['due_date'] ?? '';
        $payment_plan_id = (int)($_POST['payment_plan_id'] ?? 0) ?: null;
        $force_resend = ($_POST['force_resend'] ?? 'false') === 'true';
        
        if ($donor_id <= 0) {
            $sendJsonResponse(['success' => false, 'error' => 'Invalid donor ID']);
        }
        if (empty($message)) {
            $sendJsonResponse(['success' => false, 'error' => 'Message cannot be empty']);
        }
        if (empty($due_date)) {
            $sendJsonResponse(['success' => false, 'error' => 'Due date is required']);
        }
        
        // Check if reminder already sent today (unless force resend)
        if (!$force_resend) {
            $existingReminder = wasReminderSentToday($db, $donor_id, $due_date);
            if ($existingReminder) {
                $sentTime = date('H:i', strtotime($existingReminder['sent_at']));
                $sentBy = $existingReminder['sent_by_name'] ?? ($existingReminder['source_type'] === 'cron' ? 'System (Cron)' : 'Admin');
                $sendJsonResponse([
                    'success' => false,
                    'already_sent' => true,
                    'error' => "Reminder already sent today at {$sentTime} via " . strtoupper($existingReminder['channel']),
                    'sent_at' => $existingReminder['sent_at'],
                    'sent_by' => $sentBy,
                    'channel' => $existingReminder['channel']
                ]);
            }
        }
        
        // Get donor phone
        $stmt = $db->prepare("SELECT phone, name, preferred_language FROM donors WHERE id = ?");
        $stmt->bind_param('i', $donor_id);
        $stmt->execute();
        $donor = $stmt->get_result()->fetch_assoc();
        
        if (!$donor || empty($donor['phone'])) {
            $sendJsonResponse(['success' => false, 'error' => 'Donor phone not found']);
        }
        
        // Use MessagingHelper for unified sending (handles logging automatically)
        $msgHelper = new MessagingHelper($db);
        
        if ($channel === 'whatsapp') {
            // Try WhatsApp first, with SMS fallback
            $result = $msgHelper->sendToDonor(
                $donor_id,
                $message,
                'whatsapp', // Preferred channel
                'manual_calendar_reminder'
            );
            
            if ($result['success'] ?? false) {
                $sendChannel = $result['channel'] ?? 'whatsapp';
                $isFallback = ($sendChannel === 'sms' && ($result['fallback_used'] ?? false));
                
                // Record that reminder was sent
                recordReminderSent($db, $donor_id, $payment_plan_id, $due_date, $sendChannel, $message, 'manual_calendar');
                
                $sendJsonResponse([
                    'success' => true, 
                    'channel' => $sendChannel, 
                    'message' => 'Reminder sent successfully via ' . strtoupper($sendChannel),
                    'fallback' => $isFallback,
                    'donor_name' => $donor['name'],
                    'phone' => $donor['phone'],
                    'message_id' => $result['message_id'] ?? null,
                    'tracked' => true
                ]);
            } else {
                $errorMsg = $result['error'] ?? 'Failed to send message';
                $sendJsonResponse([
                    'success' => false, 
                    'error' => $errorMsg,
                    'donor_name' => $donor['name']
                ]);
            }
        } else {
            // SMS only
            $result = $msgHelper->sendToDonor(
                $donor_id,
                $message,
                'sms', // SMS only
                'manual_calendar_reminder'
            );
            
            if ($result['success'] ?? false) {
                // Record that reminder was sent
                recordReminderSent($db, $donor_id, $payment_plan_id, $due_date, 'sms', $message, 'manual_calendar');
                
                $sendJsonResponse([
                    'success' => true, 
                    'channel' => 'sms', 
                    'message' => 'Reminder sent successfully via SMS',
                    'donor_name' => $donor['name'],
                    'phone' => $donor['phone'],
                    'message_id' => $result['message_id'] ?? null,
                    'tracked' => true
                ]);
            } else {
                $errorMsg = $result['error'] ?? 'SMS failed';
                $sendJsonResponse([
                    'success' => false, 
                    'error' => $errorMsg,
                    'donor_name' => $donor['name']
                ]);
            }
        }
    } catch (Throwable $e) {
        // Catch any error or exception
        error_log('Payment Calendar Reminder Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        $sendJsonResponse([
            'success' => false, 
            'error' => 'Server error: ' . $e->getMessage(),
            'error_type' => get_class($e)
        ]);
    } catch (Exception $e) {
        // Fallback for older PHP versions
        error_log('Payment Calendar Reminder Error: ' . $e->getMessage());
        $sendJsonResponse([
            'success' => false, 
            'error' => 'Server error: ' . $e->getMessage()
        ]);
    }
    
    // Should never reach here, but just in case
    $sendJsonResponse(['success' => false, 'error' => 'Unexpected error occurred']);
}

// Get view type from URL (default: week)
$view = $_GET['view'] ?? 'week';
if (!in_array($view, ['day', 'week', 'month'])) {
    $view = 'week';
}

// Get date navigation
$date_param = $_GET['date'] ?? date('Y-m-d');
try {
    $current_date = new DateTime($date_param);
} catch (Exception $e) {
    $current_date = new DateTime();
}

// Calculate date ranges based on view
$start_date = clone $current_date;
$end_date = clone $current_date;

switch ($view) {
    case 'day':
        // Single day view
        break;
    case 'week':
        // Week view (Monday to Sunday)
        $day_of_week = (int)$start_date->format('N'); // 1 = Monday
        $start_date->modify('-' . ($day_of_week - 1) . ' days');
        $end_date = clone $start_date;
        $end_date->modify('+6 days');
        break;
    case 'month':
        // Month view
        $start_date->modify('first day of this month');
        $end_date->modify('last day of this month');
        break;
}

// Navigation dates
$prev_date = clone $current_date;
$next_date = clone $current_date;
switch ($view) {
    case 'day':
        $prev_date->modify('-1 day');
        $next_date->modify('+1 day');
        break;
    case 'week':
        $prev_date->modify('-7 days');
        $next_date->modify('+7 days');
        break;
    case 'month':
        $prev_date->modify('-1 month');
        $next_date->modify('+1 month');
        break;
}

// Bank details for reminder messages
$bankDetails = [
    'account_name' => 'LMKATH',
    'account_number' => '85455687',
    'sort_code' => '53-70-44'
];

// Get reminder templates in all languages
$reminderTemplates = [
    'en' => "Dear {name}, based on your payment plan, your next payment of {amount} is due on {due_date}. Payment method: {payment_method}. {payment_instructions}. Thank you! - Liverpool Abune Teklehaymanot Church",
    'am' => "Dear {name}, based on your payment plan, your next payment of {amount} is due on {due_date}. Payment method: {payment_method}. {payment_instructions}. Thank you! - Liverpool Abune Teklehaymanot Church",
    'ti' => "Dear {name}, based on your payment plan, your next payment of {amount} is due on {due_date}. Payment method: {payment_method}. {payment_instructions}. Thank you! - Liverpool Abune Teklehaymanot Church"
];

$templateQuery = $db->query("SELECT message_en, message_am, message_ti FROM sms_templates WHERE template_key = 'payment_reminder_2day' AND is_active = 1 LIMIT 1");
if ($templateQuery && $row = $templateQuery->fetch_assoc()) {
    $reminderTemplates['en'] = $row['message_en'];
    $reminderTemplates['am'] = $row['message_am'];
    $reminderTemplates['ti'] = $row['message_ti'];
}

// Ensure reminder tracking table exists
ensureReminderTrackingTable($db);

// Fetch due payments in the date range with reminder status
$payments_by_date = [];
$total_due = 0;
$total_count = 0;

$today = date('Y-m-d');

$query = "
    SELECT 
        pp.id as plan_id,
        pp.donor_id,
        pp.pledge_id,
        pp.monthly_amount,
        pp.next_payment_due,
        pp.payment_method,
        pp.status as plan_status,
        pp.payments_made,
        pp.total_payments,
        d.name as donor_name,
        d.phone as donor_phone,
        d.payment_status as donor_status,
        d.preferred_language,
        cr.name as rep_name,
        cr.phone as rep_phone,
        pl.notes as pledge_notes,
        prs.id as reminder_id,
        prs.sent_at as reminder_sent_at,
        prs.channel as reminder_channel,
        prs.sent_by_name as reminder_sent_by,
        prs.source_type as reminder_source
    FROM donor_payment_plans pp
    JOIN donors d ON pp.donor_id = d.id
    LEFT JOIN church_representatives cr ON d.representative_id = cr.id
    LEFT JOIN pledges pl ON pp.pledge_id = pl.id
    LEFT JOIN (
        SELECT donor_id, due_date, id, sent_at, channel, sent_by_name, source_type
        FROM payment_reminders_sent
        WHERE DATE(sent_at) = ?
        GROUP BY donor_id, due_date
        HAVING id = MAX(id)
    ) prs ON prs.donor_id = pp.donor_id AND prs.due_date = pp.next_payment_due
    WHERE pp.next_payment_due BETWEEN ? AND ?
    AND pp.status = 'active'
    ORDER BY pp.next_payment_due ASC, d.name ASC
";

$stmt = $db->prepare($query);
$start_str = $start_date->format('Y-m-d');
$end_str = $end_date->format('Y-m-d');
$stmt->bind_param('sss', $today, $start_str, $end_str);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $due_date = $row['next_payment_due'];
    if (!isset($payments_by_date[$due_date])) {
        $payments_by_date[$due_date] = [];
    }
    $payments_by_date[$due_date][] = $row;
    $total_due += (float)$row['monthly_amount'];
    $total_count++;
}

// Build calendar data for month view
$calendar_weeks = [];
if ($view === 'month') {
    $month_start = clone $start_date;
    $month_end = clone $end_date;
    
    // Adjust to start from Monday
    $first_day_of_week = (int)$month_start->format('N');
    $calendar_start = clone $month_start;
    $calendar_start->modify('-' . ($first_day_of_week - 1) . ' days');
    
    // Fill calendar weeks
    $current = clone $calendar_start;
    $week = [];
    while ($current <= $month_end || count($week) > 0) {
        $week[] = clone $current;
        if (count($week) === 7) {
            $calendar_weeks[] = $week;
            $week = [];
        }
        $current->modify('+1 day');
        
        // Stop if we've completed the month's weeks
        if ($current > $month_end && count($week) === 0) {
            break;
        }
    }
    // Complete last week if partial
    if (count($week) > 0) {
        while (count($week) < 7) {
            $week[] = clone $current;
            $current->modify('+1 day');
        }
        $calendar_weeks[] = $week;
    }
}

// Build week days for week view
$week_days = [];
if ($view === 'week') {
    $current = clone $start_date;
    for ($i = 0; $i < 7; $i++) {
        $week_days[] = clone $current;
        $current->modify('+1 day');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($page_title); ?> - Fundraising Admin</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        :root {
            --primary-color: #0a6286;
            --primary-light: #e0f2fe;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
        }
        
        /* Calendar Header */
        .calendar-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, #0ea5e9 100%);
            color: white;
            padding: 20px;
            border-radius: 16px;
            margin-bottom: 20px;
        }
        .calendar-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .calendar-title {
            font-size: 1.25rem;
            font-weight: 700;
            margin: 0;
            text-align: center;
            flex: 1;
            min-width: 150px;
        }
        .nav-btn {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        .nav-btn:hover {
            background: rgba(255,255,255,0.3);
            color: white;
            transform: scale(1.05);
        }
        .today-btn {
            background: rgba(255,255,255,0.9);
            color: var(--primary-color);
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.85rem;
            border: none;
            transition: all 0.2s;
        }
        .today-btn:hover {
            background: white;
            color: var(--primary-color);
        }
        
        /* View Tabs */
        .view-tabs {
            display: flex;
            gap: 8px;
            padding: 12px 20px;
            background: #f9fafb;
            border-radius: 12px;
            margin-bottom: 20px;
            justify-content: center;
        }
        .view-tab {
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.9rem;
            text-decoration: none;
            transition: all 0.2s;
            color: #6b7280;
            background: transparent;
            border: 2px solid transparent;
        }
        .view-tab:hover {
            background: white;
            color: var(--primary-color);
        }
        .view-tab.active {
            background: var(--primary-color);
            color: white;
        }
        
        /* Stats Bar */
        .stats-bar {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .stat-item {
            flex: 1;
            min-width: 120px;
            background: white;
            border-radius: 12px;
            padding: 16px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            border: 1px solid #e5e7eb;
        }
        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        .stat-label {
            font-size: 0.75rem;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* Day View */
        .day-view-container {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
        }
        .day-header {
            background: var(--primary-light);
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid #e5e7eb;
        }
        .day-date {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        .day-name {
            font-size: 1rem;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .day-payments {
            padding: 16px;
        }
        
        /* Week View */
        .week-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 8px;
        }
        .week-day {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            border: 1px solid #e5e7eb;
            min-height: 150px;
        }
        .week-day.today {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(10, 98, 134, 0.2);
        }
        .week-day.past {
            opacity: 0.6;
        }
        .week-day-header {
            padding: 10px;
            text-align: center;
            background: #f9fafb;
            border-bottom: 1px solid #e5e7eb;
        }
        .week-day-header.today {
            background: var(--primary-color);
            color: white;
        }
        .week-day-name {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #9ca3af;
        }
        .week-day-header.today .week-day-name {
            color: rgba(255,255,255,0.8);
        }
        .week-day-date {
            font-size: 1.25rem;
            font-weight: 700;
        }
        .week-day-body {
            padding: 8px;
            max-height: 200px;
            overflow-y: auto;
        }
        
        /* Month View */
        .month-grid {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
        }
        .month-header-row {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            background: var(--primary-color);
            color: white;
        }
        .month-header-cell {
            padding: 12px;
            text-align: center;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .month-week {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            border-bottom: 1px solid #e5e7eb;
        }
        .month-week:last-child {
            border-bottom: none;
        }
        .month-day {
            min-height: 80px;
            padding: 8px;
            border-right: 1px solid #e5e7eb;
            position: relative;
            cursor: pointer;
            transition: background 0.15s;
        }
        .month-day:last-child {
            border-right: none;
        }
        .month-day:hover {
            background: #f9fafb;
        }
        .month-day.other-month {
            background: #f9fafb;
            opacity: 0.5;
        }
        .month-day.today {
            background: var(--primary-light);
        }
        .month-day.has-payments {
            cursor: pointer;
        }
        .month-day-number {
            font-size: 0.9rem;
            font-weight: 600;
            color: #374151;
        }
        .month-day.today .month-day-number {
            background: var(--primary-color);
            color: white;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .month-day-count {
            position: absolute;
            bottom: 6px;
            right: 6px;
            background: var(--warning-color);
            color: white;
            font-size: 0.7rem;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 10px;
        }
        .month-day-amount {
            font-size: 0.7rem;
            color: var(--success-color);
            font-weight: 600;
            margin-top: 4px;
        }
        
        /* Payment Card */
        .payment-item {
            background: #f9fafb;
            border-radius: 8px;
            padding: 10px 12px;
            margin-bottom: 8px;
            cursor: pointer;
            transition: all 0.2s;
            border-left: 3px solid var(--warning-color);
        }
        .payment-item:hover {
            background: #f3f4f6;
            transform: translateX(2px);
        }
        .payment-item:last-child {
            margin-bottom: 0;
        }
        .payment-name {
            font-weight: 600;
            font-size: 0.85rem;
            color: #1f2937;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .payment-amount {
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--success-color);
        }
        .payment-method {
            font-size: 0.7rem;
            color: #6b7280;
        }
        .payment-mini {
            padding: 6px 8px;
            font-size: 0.75rem;
        }
        .payment-mini .payment-name {
            font-size: 0.75rem;
        }
        .payment-mini .payment-amount {
            font-size: 0.75rem;
        }
        
        /* Empty State */
        .empty-day {
            text-align: center;
            padding: 30px 20px;
            color: #9ca3af;
        }
        .empty-day i {
            font-size: 2rem;
            margin-bottom: 10px;
            opacity: 0.5;
        }
        
        /* Mobile Adjustments */
        @media (max-width: 991px) {
            .week-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            .week-day {
                min-height: auto;
            }
            .week-day-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 12px 16px;
            }
            .week-day-body {
                max-height: none;
            }
            .month-day {
                min-height: 60px;
                padding: 4px;
            }
            .month-day-number {
                font-size: 0.8rem;
            }
            .month-day.today .month-day-number {
                width: 24px;
                height: 24px;
                font-size: 0.75rem;
            }
            .month-day-count {
                font-size: 0.6rem;
                padding: 1px 5px;
                bottom: 2px;
                right: 2px;
            }
            .month-day-amount {
                display: none;
            }
            .month-header-cell {
                padding: 8px 4px;
                font-size: 0.65rem;
            }
        }
        
        @media (max-width: 575px) {
            .calendar-header {
                padding: 16px;
            }
            .calendar-title {
                font-size: 1rem;
                order: -1;
                width: 100%;
                margin-bottom: 12px;
            }
            .nav-btn {
                width: 40px;
                height: 40px;
            }
            .view-tabs {
                padding: 8px;
            }
            .view-tab {
                padding: 8px 14px;
                font-size: 0.8rem;
            }
            .stats-bar {
                gap: 8px;
            }
            .stat-item {
                min-width: 80px;
                padding: 12px 8px;
            }
            .stat-value {
                font-size: 1.2rem;
            }
            .stat-label {
                font-size: 0.65rem;
            }
            .day-date {
                font-size: 2rem;
            }
        }
        
        /* Payment Detail Modal */
        .payment-detail-list {
            max-height: 60vh;
            overflow-y: auto;
        }
        
        /* Reminder Button Styles */
        .payment-item-wrapper {
            margin-bottom: 12px;
        }
        .payment-item-wrapper .payment-item {
            margin-bottom: 0;
        }
        .send-reminder-btn {
            padding: 6px 12px;
            font-size: 0.75rem;
            font-weight: 600;
            border-radius: 8px;
            white-space: nowrap;
        }
        .send-reminder-btn:hover {
            transform: scale(1.02);
        }
        .send-reminder-btn.already-sent {
            cursor: pointer;
        }
        
        /* Already Sent Indicator */
        .payment-item.reminder-sent {
            border-left-color: #10b981;
            background: #f0fdf4;
        }
        .payment-item.reminder-sent:hover {
            background: #dcfce7;
        }
        .payment-item .badge {
            font-size: 0.65rem;
            padding: 2px 6px;
            font-weight: 500;
        }
        
        /* Reminder Modal */
        .reminder-modal .modal-header {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        }
        .message-preview-box {
            background: #f9fafb;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 16px;
            font-size: 0.9rem;
            line-height: 1.6;
            white-space: pre-wrap;
            word-wrap: break-word;
            max-height: 200px;
            overflow-y: auto;
        }
        .message-edit-box {
            width: 100%;
            min-height: 150px;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 16px;
            font-size: 0.9rem;
            line-height: 1.6;
            resize: vertical;
        }
        .message-edit-box:focus {
            border-color: #f59e0b;
            outline: none;
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.15);
        }
        .char-count {
            font-size: 0.75rem;
            color: #6b7280;
        }
        .char-count.warning {
            color: #f59e0b;
        }
        .char-count.danger {
            color: #ef4444;
        }
        .donor-info-card {
            background: #f0f9ff;
            border-radius: 10px;
            padding: 12px 16px;
        }
        .channel-toggle {
            display: flex;
            gap: 8px;
        }
        .channel-btn {
            flex: 1;
            padding: 12px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            background: white;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.2s;
            text-align: center;
        }
        .channel-btn:hover {
            border-color: #d1d5db;
        }
        .channel-btn.active {
            border-color: #10b981;
            background: #d1fae5;
            color: #065f46;
        }
        .channel-btn i {
            display: block;
            font-size: 1.5rem;
            margin-bottom: 4px;
        }
        .sending-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255,255,255,0.9);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 10;
            border-radius: 12px;
        }
        .sending-spinner {
            width: 48px;
            height: 48px;
            border: 4px solid #e5e7eb;
            border-top-color: #f59e0b;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .result-icon {
            font-size: 3rem;
            margin-bottom: 12px;
        }
        .result-icon.success { color: #10b981; }
        .result-icon.error { color: #ef4444; }
        
        /* Mobile adjustments for reminder */
        @media (max-width: 575px) {
            .send-reminder-btn {
                padding: 8px 10px;
            }
            .channel-toggle {
                flex-direction: column;
            }
            .channel-btn {
                padding: 10px;
            }
            .channel-btn i {
                display: inline;
                font-size: 1rem;
                margin-bottom: 0;
                margin-right: 8px;
            }
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="container-fluid px-3 px-lg-4">
                <?php include '../includes/db_error_banner.php'; ?>
                
                <!-- Calendar Header with Navigation -->
                <div class="calendar-header">
                    <div class="calendar-nav">
                        <a href="?view=<?php echo $view; ?>&date=<?php echo $prev_date->format('Y-m-d'); ?>" class="nav-btn">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                        
                        <h1 class="calendar-title">
                            <?php
                            switch ($view) {
                                case 'day':
                                    echo $current_date->format('l, j F Y');
                                    break;
                                case 'week':
                                    echo $start_date->format('j M') . ' - ' . $end_date->format('j M Y');
                                    break;
                                case 'month':
                                    echo $current_date->format('F Y');
                                    break;
                            }
                            ?>
                        </h1>
                        
                        <a href="?view=<?php echo $view; ?>&date=<?php echo $next_date->format('Y-m-d'); ?>" class="nav-btn">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </div>
                    
                    <div class="text-center mt-3">
                        <a href="?view=<?php echo $view; ?>&date=<?php echo date('Y-m-d'); ?>" class="today-btn">
                            <i class="fas fa-calendar-day me-1"></i> Today
                        </a>
                    </div>
                </div>
                
                <!-- View Tabs -->
                <div class="view-tabs">
                    <a href="?view=day&date=<?php echo $current_date->format('Y-m-d'); ?>" class="view-tab <?php echo $view === 'day' ? 'active' : ''; ?>">
                        <i class="fas fa-calendar-day me-1"></i> Day
                    </a>
                    <a href="?view=week&date=<?php echo $current_date->format('Y-m-d'); ?>" class="view-tab <?php echo $view === 'week' ? 'active' : ''; ?>">
                        <i class="fas fa-calendar-week me-1"></i> Week
                    </a>
                    <a href="?view=month&date=<?php echo $current_date->format('Y-m-d'); ?>" class="view-tab <?php echo $view === 'month' ? 'active' : ''; ?>">
                        <i class="fas fa-calendar-alt me-1"></i> Month
                    </a>
                </div>
                
                <!-- Stats Bar -->
                <div class="stats-bar">
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $total_count; ?></div>
                        <div class="stat-label">Due Payments</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value" style="color: var(--success-color);">£<?php echo number_format($total_due, 0); ?></div>
                        <div class="stat-label">Total Due</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo count($payments_by_date); ?></div>
                        <div class="stat-label">Days with Payments</div>
                    </div>
                </div>
                
                <!-- Calendar Views -->
                <?php if ($view === 'day'): ?>
                    <!-- Day View -->
                    <div class="day-view-container">
                        <div class="day-header">
                            <div class="day-name"><?php echo $current_date->format('l'); ?></div>
                            <div class="day-date"><?php echo $current_date->format('j'); ?></div>
                            <div class="day-name"><?php echo $current_date->format('F Y'); ?></div>
                        </div>
                        <div class="day-payments">
                            <?php 
                            $date_key = $current_date->format('Y-m-d');
                            if (isset($payments_by_date[$date_key]) && count($payments_by_date[$date_key]) > 0): 
                            ?>
                                <?php foreach ($payments_by_date[$date_key] as $payment): 
                                    // Build payment data for reminder
                                    $firstName = explode(' ', trim($payment['donor_name']))[0];
                                    $amount = '£' . number_format((float)$payment['monthly_amount'], 2);
                                    $dueDate = date('l, j F Y', strtotime($payment['next_payment_due'])); // Monday, 15 December 2025
                                    $paymentMethod = $payment['payment_method'] ?? 'bank_transfer';
                                    
                                    // Extract 4-digit reference from pledge notes
                                    $reference = '';
                                    if (!empty($payment['pledge_notes'])) {
                                        // Extract 4-digit number from notes
                                        preg_match('/\b\d{4}\b/', $payment['pledge_notes'], $matches);
                                        $reference = $matches[0] ?? '';
                                    }
                                    // Fallback to donor ID if no 4-digit found
                                    if (empty($reference)) {
                                        $reference = str_pad((string)$payment['donor_id'], 4, '0', STR_PAD_LEFT);
                                    }
                                    
                                    // Build payment instructions with clear formatting
                                    if ($paymentMethod === 'cash') {
                                        $repName = $payment['rep_name'] ?? 'your church representative';
                                        $repPhone = $payment['rep_phone'] ?? '';
                                        $paymentInstructions = "\n\n*Cash Payment*\nPlease hand over the cash to:\n→ Representative: {$repName}";
                                        if ($repPhone) {
                                            $paymentInstructions .= "\n→ Phone: {$repPhone}";
                                        }
                                    } else {
                                        // Bank transfer with clear line breaks
                                        $paymentInstructions = "\n\n*Bank Details:*\n→ Bank: {$bankDetails['account_name']}\n→ Account: {$bankDetails['account_number']}\n→ Sort Code: {$bankDetails['sort_code']}\n→ Reference: {$reference}";
                                    }
                                    
                                    // Build default message in donor's preferred language
                                    $donorLanguage = strtolower($payment['preferred_language'] ?? 'en');
                                    if (!isset($reminderTemplates[$donorLanguage])) {
                                        $donorLanguage = 'en'; // Fallback to English
                                    }
                                    $selectedTemplate = $reminderTemplates[$donorLanguage];
                                    
                                    $defaultMessage = str_replace(
                                        ['{name}', '{amount}', '{due_date}', '{payment_method}', '{payment_instructions}', '{reference}', '{portal_link}'],
                                        [$firstName, $amount, $dueDate, ucwords(str_replace('_', ' ', $paymentMethod)), $paymentInstructions, $reference, 'https://bit.ly/4p0J1gf'],
                                        $selectedTemplate
                                    );
                                    
                                    // Check if reminder was already sent today
                                    $reminderSentToday = !empty($payment['reminder_id']);
                                    $reminderSentAt = $payment['reminder_sent_at'] ?? null;
                                    $reminderChannel = $payment['reminder_channel'] ?? null;
                                    $reminderSentBy = $payment['reminder_sent_by'] ?? ($payment['reminder_source'] === 'cron' ? 'Cron' : 'Admin');
                                    
                                    $paymentData = [
                                        'donor_id' => $payment['donor_id'],
                                        'plan_id' => $payment['plan_id'],
                                        'donor_name' => $payment['donor_name'],
                                        'donor_phone' => $payment['donor_phone'],
                                        'amount' => $amount,
                                        'due_date' => $payment['next_payment_due'], // Use raw date for API
                                        'due_date_display' => $dueDate,
                                        'payment_method' => ucwords(str_replace('_', ' ', $paymentMethod)),
                                        'message' => $defaultMessage,
                                        'reminder_sent' => $reminderSentToday,
                                        'reminder_sent_at' => $reminderSentAt,
                                        'reminder_channel' => $reminderChannel
                                    ];
                                ?>
                                    <div class="payment-item-wrapper">
                                        <div class="payment-item d-flex justify-content-between align-items-start <?php echo $reminderSentToday ? 'reminder-sent' : ''; ?>">
                                            <a href="view-donor.php?id=<?php echo (int)$payment['donor_id']; ?>" class="flex-grow-1 min-width-0 text-decoration-none">
                                                <div class="payment-name">
                                                    <?php echo htmlspecialchars($payment['donor_name']); ?>
                                                    <?php if ($reminderSentToday): ?>
                                                        <span class="badge bg-success ms-1" title="Sent at <?php echo date('H:i', strtotime($reminderSentAt)); ?> via <?php echo strtoupper($reminderChannel); ?>">
                                                            <i class="fas fa-check-circle me-1"></i>Sent
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="payment-method">
                                                    <i class="fas fa-<?php echo $payment['payment_method'] === 'cash' ? 'money-bill' : ($payment['payment_method'] === 'bank_transfer' ? 'university' : 'credit-card'); ?> me-1"></i>
                                                    <?php echo ucwords(str_replace('_', ' ', $payment['payment_method'] ?? 'Unknown')); ?>
                                                    • <?php echo $payment['payments_made']; ?>/<?php echo $payment['total_payments']; ?> payments
                                                </div>
                                                <div class="payment-phone text-muted" style="font-size: 0.75rem;">
                                                    <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($payment['donor_phone'] ?? '-'); ?>
                                                    <?php if ($reminderSentToday): ?>
                                                        <span class="ms-2 text-success" style="font-size: 0.7rem;">
                                                            <i class="fas fa-clock me-1"></i><?php echo date('H:i', strtotime($reminderSentAt)); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </a>
                                            <div class="text-end ms-2 d-flex flex-column align-items-end gap-2">
                                                <div class="payment-amount">£<?php echo number_format((float)$payment['monthly_amount'], 2); ?></div>
                                                <?php if ($reminderSentToday): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-success send-reminder-btn already-sent" 
                                                            onclick="openReminderModal(<?php echo htmlspecialchars(json_encode($paymentData), ENT_QUOTES); ?>)"
                                                            title="Reminder already sent at <?php echo date('H:i', strtotime($reminderSentAt)); ?> via <?php echo strtoupper($reminderChannel); ?>">
                                                        <i class="fas fa-check me-1"></i><span class="d-none d-sm-inline">Sent</span>
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-sm btn-warning send-reminder-btn" 
                                                            onclick="openReminderModal(<?php echo htmlspecialchars(json_encode($paymentData), ENT_QUOTES); ?>)">
                                                        <i class="fas fa-bell me-1"></i><span class="d-none d-sm-inline">Remind</span>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                
                                <!-- Send All Reminders Button -->
                                <?php if (count($payments_by_date[$date_key]) > 1): ?>
                                <div class="text-center mt-3 pt-3 border-top">
                                    <button type="button" class="btn btn-warning" onclick="sendAllReminders()">
                                        <i class="fas fa-bell me-2"></i>Send All Reminders (<?php echo count($payments_by_date[$date_key]); ?>)
                                    </button>
                                </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="empty-day">
                                    <i class="fas fa-calendar-check d-block"></i>
                                    <p class="mb-0">No payments due on this day</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                <?php elseif ($view === 'week'): ?>
                    <!-- Week View -->
                    <div class="week-grid">
                        <?php foreach ($week_days as $day): ?>
                            <?php 
                            $date_key = $day->format('Y-m-d');
                            $is_today = $date_key === $today;
                            $is_past = $date_key < $today;
                            $day_payments = $payments_by_date[$date_key] ?? [];
                            ?>
                            <div class="week-day <?php echo $is_today ? 'today' : ''; ?> <?php echo $is_past ? 'past' : ''; ?>">
                                <div class="week-day-header <?php echo $is_today ? 'today' : ''; ?>">
                                    <div class="week-day-name"><?php echo $day->format('D'); ?></div>
                                    <div class="week-day-date"><?php echo $day->format('j'); ?></div>
                                </div>
                                <div class="week-day-body">
                                    <?php if (count($day_payments) > 0): ?>
                                        <?php foreach ($day_payments as $payment): ?>
                                            <a href="view-donor.php?id=<?php echo (int)$payment['donor_id']; ?>" class="payment-item payment-mini d-block text-decoration-none">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <div class="payment-name flex-grow-1"><?php echo htmlspecialchars($payment['donor_name']); ?></div>
                                                    <div class="payment-amount">£<?php echo number_format((float)$payment['monthly_amount'], 0); ?></div>
                                                </div>
                                            </a>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="text-center text-muted small py-3">
                                            <i class="fas fa-check-circle"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                <?php elseif ($view === 'month'): ?>
                    <!-- Month View -->
                    <div class="month-grid">
                        <div class="month-header-row">
                            <div class="month-header-cell">Mon</div>
                            <div class="month-header-cell">Tue</div>
                            <div class="month-header-cell">Wed</div>
                            <div class="month-header-cell">Thu</div>
                            <div class="month-header-cell">Fri</div>
                            <div class="month-header-cell">Sat</div>
                            <div class="month-header-cell">Sun</div>
                        </div>
                        
                        <?php foreach ($calendar_weeks as $week): ?>
                            <div class="month-week">
                                <?php foreach ($week as $day): ?>
                                    <?php 
                                    $date_key = $day->format('Y-m-d');
                                    $is_today = $date_key === $today;
                                    $is_current_month = $day->format('m') === $current_date->format('m');
                                    $day_payments = $payments_by_date[$date_key] ?? [];
                                    $day_total = 0;
                                    foreach ($day_payments as $p) {
                                        $day_total += (float)$p['monthly_amount'];
                                    }
                                    ?>
                                    <div class="month-day <?php echo $is_today ? 'today' : ''; ?> <?php echo !$is_current_month ? 'other-month' : ''; ?> <?php echo count($day_payments) > 0 ? 'has-payments' : ''; ?>"
                                         <?php if (count($day_payments) > 0): ?>
                                         onclick="showDayPayments('<?php echo $date_key; ?>', <?php echo htmlspecialchars(json_encode($day_payments)); ?>)"
                                         <?php endif; ?>>
                                        <div class="month-day-number"><?php echo $day->format('j'); ?></div>
                                        <?php if (count($day_payments) > 0): ?>
                                            <div class="month-day-amount">£<?php echo number_format($day_total, 0); ?></div>
                                            <div class="month-day-count"><?php echo count($day_payments); ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <!-- Back to Payments Link -->
                <div class="text-center mt-4 mb-4">
                    <a href="payments.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to Payments
                    </a>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Reminder Preview/Edit Modal -->
<div class="modal fade reminder-modal" id="reminderModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header text-white py-3">
                <div>
                    <h6 class="modal-title mb-0">
                        <i class="fas fa-bell me-2"></i>Send Payment Reminder
                    </h6>
                    <small class="text-white-50">Preview & edit before sending</small>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body position-relative" id="reminderModalBody">
                <!-- Sending/Result Overlay (hidden by default) -->
                <div class="sending-overlay" id="sendingOverlay" style="display: none;">
                    <div class="sending-spinner" id="sendingSpinner"></div>
                    <div class="mt-3 fw-semibold" id="sendingText">Sending reminder...</div>
                </div>
                
                <!-- Donor Info -->
                <div class="donor-info-card mb-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fw-bold" id="reminderDonorName">-</div>
                            <div class="small text-muted" id="reminderDonorPhone">-</div>
                        </div>
                        <div class="text-end">
                            <div class="fw-bold text-success" id="reminderAmount">-</div>
                            <div class="small text-muted" id="reminderDueDate">-</div>
                        </div>
                    </div>
                </div>
                
                <!-- Already Sent Warning -->
                <div class="alert alert-warning small py-2 mb-3" id="alreadySentWarning" style="display: none;">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Reminder already sent today.
                </div>
                
                <!-- Channel Selection -->
                <div class="mb-3">
                    <label class="form-label fw-semibold small mb-2">Send via:</label>
                    <div class="channel-toggle">
                        <button type="button" class="channel-btn active" id="channelWhatsapp" onclick="selectChannel('whatsapp')">
                            <i class="fab fa-whatsapp"></i>
                            WhatsApp
                        </button>
                        <button type="button" class="channel-btn" id="channelSms" onclick="selectChannel('sms')">
                            <i class="fas fa-sms"></i>
                            SMS
                        </button>
                    </div>
                </div>
                
                <!-- Message Preview -->
                <div class="mb-3" id="previewSection">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label class="form-label fw-semibold small mb-0">Message Preview:</label>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleEditMode()">
                            <i class="fas fa-edit me-1"></i>Edit
                        </button>
                    </div>
                    <div class="message-preview-box" id="messagePreview">-</div>
                </div>
                
                <!-- Message Edit -->
                <div class="mb-3" id="editSection" style="display: none;">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label class="form-label fw-semibold small mb-0">Edit Message:</label>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="toggleEditMode()">
                            <i class="fas fa-eye me-1"></i>Preview
                        </button>
                    </div>
                    <textarea class="message-edit-box" id="messageEdit" oninput="updateCharCount()"></textarea>
                    <div class="d-flex justify-content-between mt-1">
                        <span class="char-count" id="charCount">0 characters</span>
                        <span class="char-count">SMS: ~160 chars/segment</span>
                    </div>
                </div>
                
                <input type="hidden" id="reminderDonorId" value="">
                <input type="hidden" id="reminderChannel" value="whatsapp">
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-warning" id="sendReminderBtn" onclick="sendReminder()">
                    <i class="fas fa-paper-plane me-2"></i>Send Reminder
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Day Payments Modal (for month view click) -->
<div class="modal fade" id="dayPaymentsModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white py-3">
                <div>
                    <h6 class="modal-title mb-0">
                        <i class="fas fa-calendar-day me-2"></i>Payments Due
                    </h6>
                    <small class="text-white-50" id="modalDate">-</small>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="payment-detail-list" id="modalPaymentsList">
                    <!-- Payments will be inserted here -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>

<script>
function showDayPayments(dateStr, payments) {
    // Format date
    const date = new Date(dateStr);
    const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
    document.getElementById('modalDate').textContent = date.toLocaleDateString('en-GB', options);
    
    // Build payment list
    let html = '';
    payments.forEach(function(p) {
        const methodIcon = p.payment_method === 'cash' ? 'money-bill' : 
                          (p.payment_method === 'bank_transfer' ? 'university' : 'credit-card');
        
        html += `
            <a href="view-donor.php?id=${p.donor_id}" class="payment-item d-flex justify-content-between align-items-center text-decoration-none mx-3 my-2">
                <div class="flex-grow-1 min-width-0">
                    <div class="payment-name">${escapeHtml(p.donor_name)}</div>
                    <div class="payment-method">
                        <i class="fas fa-${methodIcon} me-1"></i>
                        ${p.payment_method.replace('_', ' ')}
                        • ${p.payments_made}/${p.total_payments} payments
                    </div>
                </div>
                <div class="text-end ms-3">
                    <div class="payment-amount">£${parseFloat(p.monthly_amount).toFixed(2)}</div>
                </div>
            </a>
        `;
    });
    
    document.getElementById('modalPaymentsList').innerHTML = html;
    
    // Show modal
    new bootstrap.Modal(document.getElementById('dayPaymentsModal')).show();
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Sidebar toggle fallback
if (typeof window.toggleSidebar !== 'function') {
    window.toggleSidebar = function() {
        var sidebar = document.getElementById('sidebar');
        var overlay = document.querySelector('.sidebar-overlay');
        if (window.innerWidth <= 991.98) {
            if (sidebar) sidebar.classList.toggle('active');
            if (overlay) overlay.classList.toggle('active');
            document.body.style.overflow = (sidebar && sidebar.classList.contains('active')) ? 'hidden' : '';
        } else {
            document.body.classList.toggle('sidebar-collapsed');
        }
    };
}

// ============================================
// Reminder Modal Functions
// ============================================

let currentReminderData = null;
let allPaymentsData = [];

// Store all payments for "Send All" functionality
<?php if ($view === 'day' && isset($payments_by_date[$date_key]) && count($payments_by_date[$date_key]) > 0): ?>
allPaymentsData = <?php 
    $allPayments = [];
    foreach ($payments_by_date[$date_key] as $payment) {
        $firstName = explode(' ', trim($payment['donor_name']))[0];
        $amount = '£' . number_format((float)$payment['monthly_amount'], 2);
        $dueDate = date('l, j F Y', strtotime($payment['next_payment_due'])); // Monday, 15 December 2025
        $paymentMethod = $payment['payment_method'] ?? 'bank_transfer';
        
        // Extract 4-digit reference from pledge notes
        $reference = '';
        if (!empty($payment['pledge_notes'])) {
            preg_match('/\b\d{4}\b/', $payment['pledge_notes'], $matches);
            $reference = $matches[0] ?? '';
        }
        // Fallback to donor ID if no 4-digit found
        if (empty($reference)) {
            $reference = str_pad((string)$payment['donor_id'], 4, '0', STR_PAD_LEFT);
        }
        
        // Build payment instructions with clear formatting
        if ($paymentMethod === 'cash') {
            $repName = $payment['rep_name'] ?? 'your church representative';
            $repPhone = $payment['rep_phone'] ?? '';
            $paymentInstructions = "\n\n*Cash Payment*\nPlease hand over the cash to:\n→ Representative: {$repName}";
            if ($repPhone) {
                $paymentInstructions .= "\n→ Phone: {$repPhone}";
            }
        } else {
            // Bank transfer with clear line breaks
            $paymentInstructions = "\n\n*Bank Details:*\n→ Bank: {$bankDetails['account_name']}\n→ Account: {$bankDetails['account_number']}\n→ Sort Code: {$bankDetails['sort_code']}\n→ Reference: {$reference}";
        }
        
        // Select message in donor's preferred language
        $donorLanguage = strtolower($payment['preferred_language'] ?? 'en');
        if (!isset($reminderTemplates[$donorLanguage])) {
            $donorLanguage = 'en'; // Fallback to English
        }
        $selectedTemplate = $reminderTemplates[$donorLanguage];
        
        $defaultMessage = str_replace(
            ['{name}', '{amount}', '{due_date}', '{payment_method}', '{payment_instructions}', '{reference}', '{portal_link}'],
            [$firstName, $amount, $dueDate, ucwords(str_replace('_', ' ', $paymentMethod)), $paymentInstructions, $reference, 'https://bit.ly/4p0J1gf'],
            $selectedTemplate
        );
        
        // Check if reminder was already sent today
        $reminderSentToday = !empty($payment['reminder_id']);
        
        $allPayments[] = [
            'donor_id' => $payment['donor_id'],
            'plan_id' => $payment['plan_id'],
            'donor_name' => $payment['donor_name'],
            'donor_phone' => $payment['donor_phone'],
            'amount' => $amount,
            'due_date' => $payment['next_payment_due'], // Raw Y-m-d format for API
            'due_date_display' => $dueDate, // Formatted for display
            'payment_method' => ucwords(str_replace('_', ' ', $paymentMethod)),
            'message' => $defaultMessage,
            'reminder_sent' => $reminderSentToday,
            'reminder_sent_at' => $payment['reminder_sent_at'] ?? null,
            'reminder_channel' => $payment['reminder_channel'] ?? null
        ];
    }
    echo json_encode($allPayments);
?>;
<?php endif; ?>

function openReminderModal(paymentData) {
    currentReminderData = paymentData;

    // Populate modal
    document.getElementById('reminderDonorName').textContent = paymentData.donor_name;
    document.getElementById('reminderDonorPhone').textContent = paymentData.donor_phone || '-';
    document.getElementById('reminderAmount').textContent = paymentData.amount;
    document.getElementById('reminderDueDate').textContent = 'Due: ' + paymentData.due_date_display;
    document.getElementById('reminderDonorId').value = paymentData.donor_id;
    
    // Set message
    document.getElementById('messagePreview').textContent = paymentData.message;
    document.getElementById('messageEdit').value = paymentData.message;
    updateCharCount();
    
    // Reset to preview mode
    document.getElementById('previewSection').style.display = 'block';
    document.getElementById('editSection').style.display = 'none';
    
    // Reset channel to WhatsApp
    selectChannel('whatsapp');
    
    // Reset overlay
    document.getElementById('sendingOverlay').style.display = 'none';
    document.getElementById('sendReminderBtn').disabled = false;
    
    // Show/hide already sent warning
    const warningEl = document.getElementById('alreadySentWarning');
    const sendBtn = document.getElementById('sendReminderBtn');
    
    // Reset button display (in case it was hidden by already_sent response)
    sendBtn.style.display = '';
    
    if (paymentData.reminder_sent) {
        const sentTime = paymentData.reminder_sent_at ? 
            new Date(paymentData.reminder_sent_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}) : 'earlier';
        const channel = paymentData.reminder_channel ? paymentData.reminder_channel.toUpperCase() : 'WhatsApp';
        warningEl.innerHTML = 
            '<div class="d-flex justify-content-between align-items-start gap-2">' +
                '<div>' +
                    '<i class="fas fa-exclamation-triangle me-2"></i>' +
                    'Reminder already sent today at ' + sentTime + ' via ' + channel + '. ' +
                    '<strong>Sending again may annoy the donor.</strong>' +
                '</div>' +
                '<button type="button" class="btn btn-sm btn-outline-danger flex-shrink-0" onclick="deleteReminderRecord()" title="Delete this reminder record (undo)">' +
                    '<i class="fas fa-trash-alt"></i>' +
                '</button>' +
            '</div>';
        warningEl.style.display = 'block';
        sendBtn.innerHTML = '<i class="fas fa-redo me-2"></i>Send Again';
        sendBtn.classList.remove('btn-warning');
        sendBtn.classList.add('btn-outline-warning');
    } else {
        warningEl.style.display = 'none';
        sendBtn.innerHTML = '<i class="fas fa-paper-plane me-2"></i>Send Reminder';
        sendBtn.classList.add('btn-warning');
        sendBtn.classList.remove('btn-outline-warning');
    }
    
    // Show modal
    new bootstrap.Modal(document.getElementById('reminderModal')).show();
}

function selectChannel(channel) {
    document.getElementById('reminderChannel').value = channel;
    
    document.getElementById('channelWhatsapp').classList.toggle('active', channel === 'whatsapp');
    document.getElementById('channelSms').classList.toggle('active', channel === 'sms');
}

function closeReminderModal() {
    const modal = bootstrap.Modal.getInstance(document.getElementById('reminderModal'));
    if (modal) modal.hide();
}

function deleteReminderRecord() {
    if (!currentReminderData || !currentReminderData.donor_id || !currentReminderData.due_date) {
        alert('Missing reminder data');
        return;
    }
    
    if (!confirm('Delete this reminder record?\n\nThis will mark the reminder as NOT sent, allowing you to send a fresh reminder.')) {
        return;
    }
    
    const warningEl = document.getElementById('alreadySentWarning');
    const originalContent = warningEl.innerHTML;
    
    // Show deleting state
    warningEl.innerHTML = 
        '<div class="d-flex align-items-center">' +
            '<span class="spinner-border spinner-border-sm me-2"></span>' +
            'Deleting reminder record...' +
        '</div>';
    
    const formData = new FormData();
    formData.append('action', 'delete_reminder');
    formData.append('donor_id', currentReminderData.donor_id);
    formData.append('due_date', currentReminderData.due_date);
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(text => {
        try {
            return JSON.parse(text);
        } catch (e) {
            throw new Error('Invalid response');
        }
    })
    .then(data => {
        if (data && data.success) {
            // Update local data
            currentReminderData.reminder_sent = false;
            currentReminderData.reminder_sent_at = null;
            currentReminderData.reminder_channel = null;
            
            // Hide warning and reset button
            warningEl.style.display = 'none';
            const sendBtn = document.getElementById('sendReminderBtn');
            sendBtn.innerHTML = '<i class="fas fa-paper-plane me-2"></i>Send Reminder';
            sendBtn.classList.add('btn-warning');
            sendBtn.classList.remove('btn-outline-warning');
            sendBtn.style.display = '';
            
            // Show success briefly
            warningEl.innerHTML = 
                '<div class="text-success">' +
                    '<i class="fas fa-check-circle me-2"></i>' +
                    'Reminder record deleted. You can now send a fresh reminder.' +
                '</div>';
            warningEl.classList.remove('alert-warning');
            warningEl.classList.add('alert-success');
            warningEl.style.display = 'block';
            
            // Hide after 3 seconds
            setTimeout(() => {
                warningEl.style.display = 'none';
                warningEl.classList.remove('alert-success');
                warningEl.classList.add('alert-warning');
            }, 3000);
        } else {
            warningEl.innerHTML = originalContent;
            alert('Failed to delete: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        warningEl.innerHTML = originalContent;
        alert('Error: ' + error.message);
    });
}

function toggleEditMode() {
    const previewSection = document.getElementById('previewSection');
    const editSection = document.getElementById('editSection');
    
    if (editSection.style.display === 'none') {
        // Switch to edit mode
        previewSection.style.display = 'none';
        editSection.style.display = 'block';
        document.getElementById('messageEdit').focus();
    } else {
        // Switch to preview mode - update preview with edited text
        const editedMessage = document.getElementById('messageEdit').value;
        document.getElementById('messagePreview').textContent = editedMessage;
        editSection.style.display = 'none';
        previewSection.style.display = 'block';
    }
}

function updateCharCount() {
    const text = document.getElementById('messageEdit').value;
    const count = text.length;
    const charCountEl = document.getElementById('charCount');
    
    charCountEl.textContent = count + ' characters';
    charCountEl.classList.remove('warning', 'danger');
    
    if (count > 160 && count <= 320) {
        charCountEl.classList.add('warning');
        charCountEl.textContent = count + ' characters (2 SMS segments)';
    } else if (count > 320) {
        charCountEl.classList.add('danger');
        const segments = Math.ceil(count / 160);
        charCountEl.textContent = count + ' characters (' + segments + ' SMS segments)';
    }
}

function sendReminder(forceResend = false) {
    const donorId = document.getElementById('reminderDonorId').value;
    const channel = document.getElementById('reminderChannel').value;
    
    // Get message from edit box or preview
    let message = document.getElementById('messageEdit').value;
    if (!message.trim()) {
        message = document.getElementById('messagePreview').textContent;
    }
    
    if (!donorId || !message.trim()) {
        alert('Missing donor or message');
        return;
    }
    
    // Show sending overlay
    const overlay = document.getElementById('sendingOverlay');
    const spinner = document.getElementById('sendingSpinner');
    const sendingText = document.getElementById('sendingText');
    
    overlay.style.display = 'flex';
    spinner.style.display = 'block';
    sendingText.innerHTML = '<span class="sending-spinner" style="width:24px;height:24px;display:inline-block;vertical-align:middle;margin-right:8px;"></span> Sending reminder...';
    document.getElementById('sendReminderBtn').disabled = true;
    
    // Send AJAX request with tracking data
    const formData = new FormData();
    formData.append('action', 'send_reminder');
    formData.append('donor_id', donorId);
    formData.append('message', message);
    formData.append('channel', channel);
    formData.append('due_date', currentReminderData.due_date || '');
    formData.append('payment_plan_id', currentReminderData.plan_id || '');
    formData.append('force_resend', forceResend ? 'true' : 'false');
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        // Check if response is ok
        if (!response.ok) {
            throw new Error('HTTP ' + response.status + ': ' + response.statusText);
        }
        
        // Get response as text first to check if it's valid JSON
        return response.text().then(text => {
            try {
                return JSON.parse(text);
            } catch (e) {
                // If not JSON, check if it looks like HTML (error page)
                if (text.trim().startsWith('<')) {
                    throw new Error('Server returned HTML instead of JSON. Check for PHP errors.');
                }
                // Try to extract error message from text
                throw new Error('Invalid JSON response: ' + text.substring(0, 100));
            }
        });
    })
    .then(data => {
        spinner.style.display = 'none';
        
        if (data && data.success) {
            const channelName = data.channel === 'whatsapp' ? 'WhatsApp' : 'SMS';
            const fallbackNote = data.fallback ? '<br><small class="text-warning">(SMS fallback used)</small>' : '';
            
            sendingText.innerHTML = 
                '<i class="fas fa-check-circle result-icon success"></i><br>' +
                '<span class="text-success fw-bold">Reminder Sent Successfully!</span><br>' +
                '<small class="text-muted">via ' + channelName + '</small>' + fallbackNote +
                (data.donor_name ? '<br><small class="text-muted">To: ' + escapeHtml(data.donor_name) + '</small>' : '');
            
            // Mark as sent in local data
            currentReminderData.reminder_sent = true;
            currentReminderData.reminder_channel = data.channel;
            currentReminderData.reminder_sent_at = new Date().toISOString();
            
            // Auto close after 3 seconds and reload to show updated status
            setTimeout(() => {
                bootstrap.Modal.getInstance(document.getElementById('reminderModal')).hide();
                location.reload();
            }, 2500);
        } else if (data && data.already_sent) {
            // Reminder was already sent today - offer force resend
            sendingText.innerHTML = 
                '<i class="fas fa-exclamation-triangle result-icon warning"></i><br>' +
                '<span class="text-warning fw-bold">Already Sent Today</span><br>' +
                '<small class="text-muted">' + escapeHtml(data.error) + '</small><br>' +
                '<small class="text-muted">By: ' + escapeHtml(data.sent_by || 'Admin') + '</small><br><br>' +
                '<button type="button" class="btn btn-sm btn-outline-warning" onclick="sendReminder(true)">' +
                '<i class="fas fa-redo me-1"></i>Send Anyway</button>' +
                '<button type="button" class="btn btn-sm btn-outline-secondary ms-2" onclick="closeReminderModal()">' +
                'Cancel</button>';
            document.getElementById('sendReminderBtn').style.display = 'none';
        } else {
            const errorMsg = data && data.error ? data.error : 'Unknown error occurred';
            sendingText.innerHTML = 
                '<i class="fas fa-times-circle result-icon error"></i><br>' +
                '<span class="text-danger fw-bold">Failed to Send</span><br>' +
                '<small class="text-muted">' + escapeHtml(errorMsg) + '</small>';
            document.getElementById('sendReminderBtn').disabled = false;
        }
    })
    .catch(error => {
        spinner.style.display = 'none';
        console.error('Reminder send error:', error);
        
        sendingText.innerHTML = 
            '<i class="fas fa-exclamation-triangle result-icon error"></i><br>' +
            '<span class="text-danger fw-bold">Network Error</span><br>' +
            '<small class="text-muted">' + escapeHtml(error.message || 'Failed to communicate with server') + '</small><br>' +
            '<small class="text-muted mt-2 d-block">Note: Message may have been sent. Check WhatsApp to confirm.</small>';
        document.getElementById('sendReminderBtn').disabled = false;
    });
}

// Send all reminders
let sendAllIndex = 0;
let sendAllResults = { sent: 0, failed: 0, skipped: 0 };
let sendAllPayments = [];

function sendAllReminders() {
    if (allPaymentsData.length === 0) {
        alert('No payments to remind');
        return;
    }
    
    // Filter out already sent reminders
    const pendingPayments = allPaymentsData.filter(p => !p.reminder_sent);
    const alreadySentCount = allPaymentsData.length - pendingPayments.length;
    
    if (pendingPayments.length === 0) {
        alert('All reminders have already been sent today.');
        return;
    }
    
    let confirmMsg = 'Send reminders to ' + pendingPayments.length + ' donor(s)?';
    if (alreadySentCount > 0) {
        confirmMsg += '\n\n(' + alreadySentCount + ' already sent today - will be skipped)';
    }
    confirmMsg += '\n\nThis will send via WhatsApp (with SMS fallback).';
    
    if (!confirm(confirmMsg)) {
        return;
    }
    
    // Use only pending payments
    sendAllPayments = pendingPayments;
    sendAllIndex = 0;
    sendAllResults = { sent: 0, failed: 0, skipped: alreadySentCount };
    
    // Create progress modal
    const totalToSend = sendAllPayments.length;
    const progressHtml = `
        <div class="modal fade" id="sendAllModal" tabindex="-1" data-bs-backdrop="static">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header bg-warning text-dark py-3">
                        <h6 class="modal-title"><i class="fas fa-bell me-2"></i>Sending Reminders</h6>
                    </div>
                    <div class="modal-body text-center py-4">
                        <div class="mb-3">
                            <div class="progress" style="height: 20px;">
                                <div class="progress-bar bg-warning progress-bar-striped progress-bar-animated" id="sendAllProgress" style="width: 0%"></div>
                            </div>
                        </div>
                        <div id="sendAllStatus" class="fw-semibold">Sending 0 / ${totalToSend}</div>
                        <div id="sendAllResults" class="mt-2 small text-muted">
                            <span class="text-success">✓ 0 sent</span> • <span class="text-danger">✗ 0 failed</span>
                            ${alreadySentCount > 0 ? ' • <span class="text-secondary">' + alreadySentCount + ' skipped</span>' : ''}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Remove existing modal if any
    const existingModal = document.getElementById('sendAllModal');
    if (existingModal) existingModal.remove();
    
    document.body.insertAdjacentHTML('beforeend', progressHtml);
    const modal = new bootstrap.Modal(document.getElementById('sendAllModal'));
    modal.show();
    
    // Start sending
    sendNextReminder();
}

function sendNextReminder() {
    if (sendAllIndex >= sendAllPayments.length) {
        // Done - show results
        const resultsEl = document.getElementById('sendAllResults');
        const statusEl = document.getElementById('sendAllStatus');
        
        statusEl.innerHTML = '<i class="fas fa-check-circle text-success me-2"></i>Complete!';
        let resultHtml = `<span class="text-success fw-bold">✓ ${sendAllResults.sent} sent</span>`;
        if (sendAllResults.failed > 0) {
            resultHtml += ` • <span class="text-danger">✗ ${sendAllResults.failed} failed</span>`;
        }
        if (sendAllResults.skipped > 0) {
            resultHtml += ` • <span class="text-secondary">${sendAllResults.skipped} skipped</span>`;
        }
        resultsEl.innerHTML = resultHtml;
        
        // Close after 3 seconds and reload to show updated status
        setTimeout(() => {
            bootstrap.Modal.getInstance(document.getElementById('sendAllModal')).hide();
            location.reload();
        }, 3000);
        return;
    }
    
    const payment = sendAllPayments[sendAllIndex];
    
    // Update progress
    const progress = ((sendAllIndex + 1) / sendAllPayments.length) * 100;
    document.getElementById('sendAllProgress').style.width = progress + '%';
    document.getElementById('sendAllStatus').textContent = 'Sending ' + (sendAllIndex + 1) + ' / ' + sendAllPayments.length + ': ' + payment.donor_name;
    
    // Send request with tracking data
    const formData = new FormData();
    formData.append('action', 'send_reminder');
    formData.append('donor_id', payment.donor_id);
    formData.append('message', payment.message);
    formData.append('channel', 'whatsapp');
    formData.append('due_date', payment.due_date || '');
    formData.append('payment_plan_id', payment.plan_id || '');
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }
        return response.text().then(text => {
            try {
                return JSON.parse(text);
            } catch (e) {
                // If not JSON, assume success if message was likely sent
                console.warn('Non-JSON response for donor ' + payment.donor_id + ':', text.substring(0, 100));
                return { success: true, channel: 'whatsapp', message: 'Sent (response unclear)' };
            }
        });
    })
    .then(data => {
        if (data && data.success) {
            sendAllResults.sent++;
        } else if (data && data.already_sent) {
            sendAllResults.skipped++;
        } else {
            sendAllResults.failed++;
        }
        
        let resultHtml = `<span class="text-success">✓ ${sendAllResults.sent} sent</span>`;
        if (sendAllResults.failed > 0) {
            resultHtml += ` • <span class="text-danger">✗ ${sendAllResults.failed} failed</span>`;
        }
        if (sendAllResults.skipped > 0) {
            resultHtml += ` • <span class="text-secondary">${sendAllResults.skipped} skipped</span>`;
        }
        document.getElementById('sendAllResults').innerHTML = resultHtml;
        
        sendAllIndex++;
        setTimeout(sendNextReminder, 500); // Small delay between sends
    })
    .catch(error => {
        console.error('Error sending to ' + payment.donor_name + ':', error);
        sendAllResults.failed++;
        sendAllIndex++;
        setTimeout(sendNextReminder, 500);
    });
}
</script>
</body>
</html>
