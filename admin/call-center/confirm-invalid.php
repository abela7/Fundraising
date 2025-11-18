<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

// Set timezone to London for call center (handles GMT/BST automatically)
date_default_timezone_set('Europe/London');

try {
    $db = db();
    $user_id = (int)$_SESSION['user']['id'];
    $user_name = $_SESSION['user']['name'] ?? 'Unknown';
    
    // Get parameters
    $session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
    $donor_id = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0;
    $queue_id = isset($_GET['queue_id']) ? (int)$_GET['queue_id'] : 0;
    $reason = isset($_GET['reason']) ? $_GET['reason'] : 'not_working';
    
    if (!$donor_id || !$queue_id) {
        header('Location: index.php');
        exit;
    }
    
    // Get donor info
    $donor_query = "SELECT name, phone FROM donors WHERE id = ? LIMIT 1";
    $stmt = $db->prepare($donor_query);
    $stmt->bind_param('i', $donor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $donor = $result->fetch_object();
    $stmt->close();
    
    if (!$donor) {
        header('Location: index.php');
        exit;
    }
    
    // Get call details from session if exists
    // Use current browser time (will be set via JavaScript)
    $call_datetime_iso = date('c'); // ISO 8601 format for JavaScript
    $agent_name = $user_name;
    
    if ($session_id > 0) {
        $session_query = "
            SELECT call_started_at, agent_id 
            FROM call_center_sessions 
            WHERE id = ? AND agent_id = ?
        ";
        $stmt = $db->prepare($session_query);
        if ($stmt) {
            $stmt->bind_param('ii', $session_id, $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $session = $result->fetch_object();
            $stmt->close();
            
            if ($session && $session->call_started_at) {
                // Pass raw datetime to JavaScript - let browser handle timezone conversion
                // Format as ISO 8601 for JavaScript Date parsing
                $call_datetime_iso = $session->call_started_at;
                
                // Get agent name
                $agent_query = "SELECT name FROM users WHERE id = ? LIMIT 1";
                $stmt = $db->prepare($agent_query);
                $stmt->bind_param('i', $session->agent_id);
                $stmt->execute();
                $agent_result = $stmt->get_result();
                $agent = $agent_result->fetch_object();
                $stmt->close();
                if ($agent) {
                    $agent_name = $agent->name;
                }
            }
        }
    }
    
    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm']) && $_POST['confirm'] === 'yes') {
        // Process the mark-invalid action
        $outcome_map = [
            'not_working' => 'number_not_in_service',
            'disconnected' => 'number_disconnected',
            'invalid' => 'invalid_number',
            'wrong_number' => 'wrong_number',
            'network_error' => 'network_error'
        ];
        $outcome = $outcome_map[$reason] ?? 'number_not_in_service';
        
        // Update session if session_id exists
        if ($session_id > 0) {
            $update_session = "
                UPDATE call_center_sessions 
                SET outcome = ?,
                    disposition = 'mark_for_removal',
                    call_ended_at = NOW(),
                    notes = ?
                WHERE id = ? AND agent_id = ?
            ";
            $notes = "Number marked as invalid: " . ucfirst(str_replace('_', ' ', $reason));
            $stmt = $db->prepare($update_session);
            if ($stmt) {
                $stmt->bind_param('ssii', $outcome, $notes, $session_id, $user_id);
                $stmt->execute();
                $stmt->close();
            }
        } else {
            // Create session for tracking
            $call_started_at = date('Y-m-d H:i:s');
            $insert_session = "
                INSERT INTO call_center_sessions 
                (donor_id, agent_id, call_started_at, call_ended_at, outcome, disposition, conversation_stage, notes, created_at)
                VALUES (?, ?, ?, NOW(), ?, 'mark_for_removal', 'no_connection', ?, NOW())
            ";
            $notes = "Number marked as invalid: " . ucfirst(str_replace('_', ' ', $reason));
            $stmt = $db->prepare($insert_session);
            if ($stmt) {
                $stmt->bind_param('iisss', $donor_id, $user_id, $call_started_at, $outcome, $notes);
                $stmt->execute();
                $stmt->close();
            }
        }
        
        // Update queue status
        $update_queue = "
            UPDATE call_center_queues 
            SET status = 'completed',
                completion_notes = ?,
                completed_at = NOW(),
                last_attempt_outcome = ?
            WHERE id = ?
        ";
        $completion_notes = "Number not working: " . ucfirst(str_replace('_', ' ', $reason));
        $stmt = $db->prepare($update_queue);
        if ($stmt) {
            $stmt->bind_param('ssi', $completion_notes, $outcome, $queue_id);
            $stmt->execute();
            $stmt->close();
        }
        
        // Get browser-provided time (in browser's local timezone)
        $call_date = isset($_POST['call_date']) ? $_POST['call_date'] : date('Y-m-d');
        $call_time = isset($_POST['call_time']) ? $_POST['call_time'] : date('H:i:s');
        
        // Redirect to success page with details
        header('Location: mark-invalid.php?donor_id=' . $donor_id . '&queue_id=' . $queue_id . '&call_date=' . urlencode($call_date) . '&call_time=' . urlencode($call_time) . '&agent=' . urlencode($agent_name));
        exit;
    }
    
} catch (Exception $e) {
    error_log("Confirm Invalid Error: " . $e->getMessage());
    header('Location: index.php?error=1');
    exit;
}

$page_title = 'Confirm Invalid Number';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $page_title; ?> - Call Center</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <link rel="stylesheet" href="assets/call-center.css">
    <style>
        .confirm-invalid-page {
            max-width: 600px;
            margin: 0 auto;
            padding: 0.75rem;
        }
        
        .content-header {
            margin-bottom: 1rem;
        }
        
        .content-title {
            font-size: 1.375rem;
            font-weight: 700;
            color: #0a6286;
            margin: 0;
        }
        
        .content-subtitle {
            font-size: 0.875rem;
            color: #64748b;
            margin: 0.25rem 0 0 0;
        }
        
        .donor-header {
            background: #0a6286;
            color: white;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
            text-align: center;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        
        .donor-header h4 {
            margin: 0 0 0.375rem 0;
            font-size: 1.125rem;
            font-weight: 700;
        }
        
        .donor-header .phone {
            font-size: 0.875rem;
            opacity: 0.95;
        }
        
        .confirmation-card {
            background: white;
            border: 2px solid #ef4444;
            border-radius: 12px;
            padding: 1.5rem 1rem;
            text-align: center;
            margin-bottom: 1rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }
        
        .confirmation-icon {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: #fef2f2;
            color: #ef4444;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 2rem;
        }
        
        .confirmation-question {
            font-size: 1.125rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 1rem;
        }
        
        .call-details {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            text-align: left;
        }
        
        .call-details-title {
            font-size: 0.875rem;
            font-weight: 700;
            color: #0a6286;
            margin-bottom: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.625rem 0;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .detail-row:last-child {
            border-bottom: none;
        }
        
        .detail-label {
            font-size: 0.875rem;
            color: #64748b;
            font-weight: 600;
        }
        
        .detail-value {
            font-size: 0.875rem;
            color: #1e293b;
            font-weight: 700;
        }
        
        .action-buttons {
            display: flex;
            gap: 0.75rem;
            margin-top: 1rem;
        }
        
        .action-buttons .btn {
            flex: 1;
            padding: 0.75rem 1rem;
            font-size: 0.9375rem;
            font-weight: 600;
        }
        
        .btn-confirm {
            background: #ef4444;
            border-color: #ef4444;
            color: white;
        }
        
        .btn-confirm:hover {
            background: #dc2626;
            border-color: #dc2626;
            color: white;
        }
        
        @media (max-width: 767px) {
            .confirm-invalid-page {
                padding: 0.5rem;
            }
            
            .content-title {
                font-size: 1.25rem;
            }
            
            .donor-header {
                padding: 0.875rem;
            }
            
            .confirmation-card {
                padding: 1.25rem 1rem;
            }
            
            .confirmation-icon {
                width: 60px;
                height: 60px;
                font-size: 1.75rem;
            }
            
            .confirmation-question {
                font-size: 1rem;
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
            <div class="confirm-invalid-page">
                <div class="content-header mb-4">
                    <h1 class="content-title">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Confirm Invalid Number
                    </h1>
                    <p class="content-subtitle">Please confirm before marking this number as invalid</p>
                </div>
                
                <div class="donor-header">
                    <h4><?php echo htmlspecialchars($donor->name); ?></h4>
                    <div class="phone">
                        <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($donor->phone); ?>
                    </div>
                </div>
                
                <div class="confirmation-card">
                    <div class="confirmation-icon">
                        <i class="fas fa-question-circle"></i>
                    </div>
                    <div class="confirmation-question">
                        Are you sure the donor didn't pick up?
                    </div>
                    
                    <div class="call-details">
                        <div class="call-details-title">
                            <i class="fas fa-info-circle me-1"></i>Call Details
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Day of Call</span>
                            <span class="detail-value" id="call-date">Loading...</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Time</span>
                            <span class="detail-value" id="call-time">Loading...</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Agent</span>
                            <span class="detail-value"><?php echo htmlspecialchars($agent_name); ?></span>
                        </div>
                    </div>
                    
                    <form method="POST" action="" id="confirm-form">
                        <input type="hidden" name="confirm" value="yes">
                        <input type="hidden" name="call_date" id="call_date_input" value="">
                        <input type="hidden" name="call_time" id="call_time_input" value="">
                        <div class="action-buttons">
                            <a href="call-status.php?donor_id=<?php echo $donor_id; ?>&queue_id=<?php echo $queue_id; ?><?php echo $session_id > 0 ? '&session_id=' . $session_id : ''; ?>" 
                               class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left me-2"></i>No, Go Back
                            </a>
                            <button type="submit" class="btn btn-confirm">
                                <i class="fas fa-check me-2"></i>Yes, Mark as Invalid
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
(function() {
    // Always use browser's current time for display (most accurate)
    // This ensures the time shown matches the user's device timezone
    const callDateTime = new Date();
    
    // Format date and time in browser's local timezone
    const formatDate = (date) => {
        const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        const months = ['January', 'February', 'March', 'April', 'May', 'June', 
                       'July', 'August', 'September', 'October', 'November', 'December'];
        return days[date.getDay()] + ', ' + months[date.getMonth()] + ' ' + 
               date.getDate() + ', ' + date.getFullYear();
    };
    
    const formatTime = (date) => {
        let hours = date.getHours();
        const minutes = date.getMinutes();
        const ampm = hours >= 12 ? 'PM' : 'AM';
        hours = hours % 12;
        hours = hours ? hours : 12; // 0 should be 12
        const minutesStr = minutes < 10 ? '0' + minutes : minutes;
        return hours + ':' + minutesStr + ' ' + ampm;
    };
    
    // Display formatted date and time
    document.getElementById('call-date').textContent = formatDate(callDateTime);
    document.getElementById('call-time').textContent = formatTime(callDateTime);
    
    // Set hidden inputs for form submission (use browser's current time when submitting)
    const form = document.getElementById('confirm-form');
    if (form) {
        form.addEventListener('submit', function(e) {
            const now = new Date();
            const year = now.getFullYear();
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const day = String(now.getDate()).padStart(2, '0');
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');
            
            document.getElementById('call_date_input').value = year + '-' + month + '-' + day;
            document.getElementById('call_time_input').value = hours + ':' + minutes + ':' + seconds;
        });
    }
})();
</script>
</body>
</html>

