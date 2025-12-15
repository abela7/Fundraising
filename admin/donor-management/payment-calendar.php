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

// Handle AJAX reminder send
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_reminder') {
    // Clean any previous output
    ob_clean();
    
    // Set headers
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    
    $response = ['success' => false, 'error' => 'Unknown error'];
    
    try {
        $donor_id = (int)($_POST['donor_id'] ?? 0);
        $message = trim($_POST['message'] ?? '');
        $channel = $_POST['channel'] ?? 'whatsapp';
        
        if ($donor_id <= 0) {
            $response = ['success' => false, 'error' => 'Invalid donor ID'];
            echo json_encode($response);
            exit;
        }
        if (empty($message)) {
            $response = ['success' => false, 'error' => 'Message cannot be empty'];
            echo json_encode($response);
            exit;
        }
        
        // Get donor phone
        $stmt = $db->prepare("SELECT phone, name, preferred_language FROM donors WHERE id = ?");
        $stmt->bind_param('i', $donor_id);
        $stmt->execute();
        $donor = $stmt->get_result()->fetch_assoc();
        
        if (!$donor || empty($donor['phone'])) {
            $response = ['success' => false, 'error' => 'Donor phone not found'];
            echo json_encode($response);
            exit;
        }
        
        // Send via MessagingHelper or directly
        $msgHelper = new MessagingHelper($db);
        
        if ($channel === 'whatsapp') {
            // Try WhatsApp first
            try {
                $whatsapp = UltraMsgService::fromDatabase($db);
                $phone = preg_replace('/[^0-9]/', '', $donor['phone']);
                if (strpos($phone, '0') === 0) {
                    $phone = '44' . substr($phone, 1);
                }
                $result = $whatsapp->send($phone, $message);
                
                // Log the message
                try {
                    $msgHelper->logMessage($donor_id, $donor['phone'], 'whatsapp', $message, 'sent', null, 'manual_calendar_reminder');
                } catch (Exception $logError) {
                    // Log error but don't fail the send
                }
                
                $response = ['success' => true, 'channel' => 'whatsapp', 'message' => 'Reminder sent via WhatsApp'];
                echo json_encode($response);
                exit;
            } catch (Exception $e) {
                // Fallback to SMS
                try {
                    $smsResult = $msgHelper->sendSMS($donor_id, $message, 'manual_calendar_reminder');
                    if ($smsResult['success'] ?? false) {
                        $response = ['success' => true, 'channel' => 'sms', 'message' => 'Reminder sent via SMS (WhatsApp unavailable)'];
                    } else {
                        $response = ['success' => false, 'error' => 'Failed to send: ' . ($smsResult['error'] ?? 'SMS failed')];
                    }
                } catch (Exception $smsError) {
                    $response = ['success' => false, 'error' => 'WhatsApp failed: ' . $e->getMessage() . ', SMS also failed'];
                }
                echo json_encode($response);
                exit;
            }
        } else {
            // SMS only
            try {
                $smsResult = $msgHelper->sendSMS($donor_id, $message, 'manual_calendar_reminder');
                if ($smsResult['success'] ?? false) {
                    $response = ['success' => true, 'channel' => 'sms', 'message' => 'Reminder sent via SMS'];
                } else {
                    $response = ['success' => false, 'error' => 'Failed to send: ' . ($smsResult['error'] ?? 'SMS failed')];
                }
            } catch (Exception $smsError) {
                $response = ['success' => false, 'error' => 'SMS error: ' . $smsError->getMessage()];
            }
            echo json_encode($response);
            exit;
        }
    } catch (Exception $e) {
        $response = ['success' => false, 'error' => $e->getMessage()];
        echo json_encode($response);
        exit;
    }
    
    // Fallback - should never reach here
    echo json_encode($response);
    exit;
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

// Get reminder template
$reminderTemplate = "Dear {name}, based on your payment plan, your next payment of {amount} is due on {due_date}. Payment method: {payment_method}. {payment_instructions}. Thank you! - Liverpool Abune Teklehaymanot Church";

$templateQuery = $db->query("SELECT message_en FROM sms_templates WHERE template_key = 'payment_reminder_2day' AND is_active = 1 LIMIT 1");
if ($templateQuery && $row = $templateQuery->fetch_assoc()) {
    $reminderTemplate = $row['message_en'];
}

// Fetch due payments in the date range
$payments_by_date = [];
$total_due = 0;
$total_count = 0;

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
        pl.notes as pledge_notes
    FROM donor_payment_plans pp
    JOIN donors d ON pp.donor_id = d.id
    LEFT JOIN church_representatives cr ON d.representative_id = cr.id
    LEFT JOIN pledges pl ON pp.pledge_id = pl.id
    WHERE pp.next_payment_due BETWEEN ? AND ?
    AND pp.status = 'active'
    ORDER BY pp.next_payment_due ASC, d.name ASC
";

$stmt = $db->prepare($query);
$start_str = $start_date->format('Y-m-d');
$end_str = $end_date->format('Y-m-d');
$stmt->bind_param('ss', $start_str, $end_str);
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

// Get today's date for highlighting
$today = date('Y-m-d');

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
                                    $dueDate = date('d/m/Y', strtotime($payment['next_payment_due']));
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
                                    
                                    // Build payment instructions
                                    if ($paymentMethod === 'cash') {
                                        $repName = $payment['rep_name'] ?? 'your church representative';
                                        $repPhone = $payment['rep_phone'] ?? '';
                                        $paymentInstructions = "Please hand over the cash to {$repName}" . ($repPhone ? " ({$repPhone})" : '');
                                    } else {
                                        $paymentInstructions = "Bank: {$bankDetails['account_name']}, Account: {$bankDetails['account_number']}, Sort Code: {$bankDetails['sort_code']}, Reference: {$reference}";
                                    }
                                    
                                    // Build default message
                                    $defaultMessage = str_replace(
                                        ['{name}', '{amount}', '{due_date}', '{payment_method}', '{payment_instructions}', '{reference}', '{portal_link}'],
                                        [$firstName, $amount, $dueDate, ucwords(str_replace('_', ' ', $paymentMethod)), $paymentInstructions, $reference, 'https://bit.ly/4p0J1gf'],
                                        $reminderTemplate
                                    );
                                    
                                    $paymentData = [
                                        'donor_id' => $payment['donor_id'],
                                        'donor_name' => $payment['donor_name'],
                                        'donor_phone' => $payment['donor_phone'],
                                        'amount' => $amount,
                                        'due_date' => $dueDate,
                                        'payment_method' => ucwords(str_replace('_', ' ', $paymentMethod)),
                                        'message' => $defaultMessage
                                    ];
                                ?>
                                    <div class="payment-item-wrapper">
                                        <div class="payment-item d-flex justify-content-between align-items-start">
                                            <a href="view-donor.php?id=<?php echo (int)$payment['donor_id']; ?>" class="flex-grow-1 min-width-0 text-decoration-none">
                                                <div class="payment-name"><?php echo htmlspecialchars($payment['donor_name']); ?></div>
                                                <div class="payment-method">
                                                    <i class="fas fa-<?php echo $payment['payment_method'] === 'cash' ? 'money-bill' : ($payment['payment_method'] === 'bank_transfer' ? 'university' : 'credit-card'); ?> me-1"></i>
                                                    <?php echo ucwords(str_replace('_', ' ', $payment['payment_method'] ?? 'Unknown')); ?>
                                                    • <?php echo $payment['payments_made']; ?>/<?php echo $payment['total_payments']; ?> payments
                                                </div>
                                                <div class="payment-phone text-muted" style="font-size: 0.75rem;">
                                                    <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($payment['donor_phone'] ?? '-'); ?>
                                                </div>
                                            </a>
                                            <div class="text-end ms-2 d-flex flex-column align-items-end gap-2">
                                                <div class="payment-amount">£<?php echo number_format((float)$payment['monthly_amount'], 2); ?></div>
                                                <button type="button" class="btn btn-sm btn-warning send-reminder-btn" 
                                                        onclick="openReminderModal(<?php echo htmlspecialchars(json_encode($paymentData), ENT_QUOTES); ?>)">
                                                    <i class="fas fa-bell me-1"></i><span class="d-none d-sm-inline">Remind</span>
                                                </button>
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
        $dueDate = date('d/m/Y', strtotime($payment['next_payment_due']));
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
        
        if ($paymentMethod === 'cash') {
            $repName = $payment['rep_name'] ?? 'your church representative';
            $repPhone = $payment['rep_phone'] ?? '';
            $paymentInstructions = "Please hand over the cash to {$repName}" . ($repPhone ? " ({$repPhone})" : '');
        } else {
            $paymentInstructions = "Bank: {$bankDetails['account_name']}, Account: {$bankDetails['account_number']}, Sort Code: {$bankDetails['sort_code']}, Reference: {$reference}";
        }
        
        $defaultMessage = str_replace(
            ['{name}', '{amount}', '{due_date}', '{payment_method}', '{payment_instructions}', '{reference}', '{portal_link}'],
            [$firstName, $amount, $dueDate, ucwords(str_replace('_', ' ', $paymentMethod)), $paymentInstructions, $reference, 'https://bit.ly/4p0J1gf'],
            $reminderTemplate
        );
        
        $allPayments[] = [
            'donor_id' => $payment['donor_id'],
            'donor_name' => $payment['donor_name'],
            'donor_phone' => $payment['donor_phone'],
            'amount' => $amount,
            'due_date' => $dueDate,
            'payment_method' => ucwords(str_replace('_', ' ', $paymentMethod)),
            'message' => $defaultMessage
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
    document.getElementById('reminderDueDate').textContent = 'Due: ' + paymentData.due_date;
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
    
    // Show modal
    new bootstrap.Modal(document.getElementById('reminderModal')).show();
}

function selectChannel(channel) {
    document.getElementById('reminderChannel').value = channel;
    
    document.getElementById('channelWhatsapp').classList.toggle('active', channel === 'whatsapp');
    document.getElementById('channelSms').classList.toggle('active', channel === 'sms');
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

function sendReminder() {
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
    
    // Send AJAX request
    const formData = new FormData();
    formData.append('action', 'send_reminder');
    formData.append('donor_id', donorId);
    formData.append('message', message);
    formData.append('channel', channel);
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        spinner.style.display = 'none';
        
        if (data.success) {
            sendingText.innerHTML = '<i class="fas fa-check-circle result-icon success"></i><br><span class="text-success fw-bold">Reminder Sent!</span><br><small class="text-muted">via ' + (data.channel || channel) + '</small>';
            
            // Auto close after 2 seconds
            setTimeout(() => {
                bootstrap.Modal.getInstance(document.getElementById('reminderModal')).hide();
            }, 2000);
        } else {
            sendingText.innerHTML = '<i class="fas fa-times-circle result-icon error"></i><br><span class="text-danger fw-bold">Failed to Send</span><br><small class="text-muted">' + escapeHtml(data.error || 'Unknown error') + '</small>';
            document.getElementById('sendReminderBtn').disabled = false;
        }
    })
    .catch(error => {
        spinner.style.display = 'none';
        sendingText.innerHTML = '<i class="fas fa-times-circle result-icon error"></i><br><span class="text-danger fw-bold">Error</span><br><small class="text-muted">' + escapeHtml(error.message) + '</small>';
        document.getElementById('sendReminderBtn').disabled = false;
    });
}

// Send all reminders
let sendAllIndex = 0;
let sendAllResults = { sent: 0, failed: 0 };

function sendAllReminders() {
    if (allPaymentsData.length === 0) {
        alert('No payments to remind');
        return;
    }
    
    if (!confirm('Send reminders to all ' + allPaymentsData.length + ' donors?\n\nThis will send via WhatsApp (with SMS fallback).')) {
        return;
    }
    
    sendAllIndex = 0;
    sendAllResults = { sent: 0, failed: 0 };
    
    // Create progress modal
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
                        <div id="sendAllStatus" class="fw-semibold">Sending 0 / ${allPaymentsData.length}</div>
                        <div id="sendAllResults" class="mt-2 small text-muted">
                            <span class="text-success">✓ 0 sent</span> • <span class="text-danger">✗ 0 failed</span>
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
    if (sendAllIndex >= allPaymentsData.length) {
        // Done - show results
        const resultsEl = document.getElementById('sendAllResults');
        const statusEl = document.getElementById('sendAllStatus');
        
        statusEl.innerHTML = '<i class="fas fa-check-circle text-success me-2"></i>Complete!';
        resultsEl.innerHTML = `<span class="text-success fw-bold">✓ ${sendAllResults.sent} sent</span> • <span class="text-danger">${sendAllResults.failed > 0 ? '✗ ' + sendAllResults.failed + ' failed' : ''}</span>`;
        
        // Close after 3 seconds and reload
        setTimeout(() => {
            bootstrap.Modal.getInstance(document.getElementById('sendAllModal')).hide();
            // Optionally reload to update UI
        }, 3000);
        return;
    }
    
    const payment = allPaymentsData[sendAllIndex];
    
    // Update progress
    const progress = ((sendAllIndex + 1) / allPaymentsData.length) * 100;
    document.getElementById('sendAllProgress').style.width = progress + '%';
    document.getElementById('sendAllStatus').textContent = 'Sending ' + (sendAllIndex + 1) + ' / ' + allPaymentsData.length + ': ' + payment.donor_name;
    
    // Send request
    const formData = new FormData();
    formData.append('action', 'send_reminder');
    formData.append('donor_id', payment.donor_id);
    formData.append('message', payment.message);
    formData.append('channel', 'whatsapp');
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            sendAllResults.sent++;
        } else {
            sendAllResults.failed++;
        }
        
        document.getElementById('sendAllResults').innerHTML = `<span class="text-success">✓ ${sendAllResults.sent} sent</span> • <span class="text-danger">${sendAllResults.failed > 0 ? '✗ ' + sendAllResults.failed + ' failed' : ''}</span>`;
        
        sendAllIndex++;
        setTimeout(sendNextReminder, 500); // Small delay between sends
    })
    .catch(error => {
        sendAllResults.failed++;
        sendAllIndex++;
        setTimeout(sendNextReminder, 500);
    });
}
</script>
</body>
</html>
