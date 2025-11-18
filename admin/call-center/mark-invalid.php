<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

try {
    $db = db();
    $user_id = (int)$_SESSION['user']['id'];
    
    // Get parameters
    $session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
    $donor_id = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0;
    $queue_id = isset($_GET['queue_id']) ? (int)$_GET['queue_id'] : 0;
    $reason = isset($_GET['reason']) ? $_GET['reason'] : 'not_working';
    
    if (!$donor_id || !$queue_id) {
        header('Location: index.php');
        exit;
    }
    
    // Get donor name for display
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
    
    // Map reason to outcome enum value
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
        // If no session exists, create one for tracking
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
    
} catch (Exception $e) {
    error_log("Mark Invalid Error: " . $e->getMessage());
    header('Location: index.php?error=1');
    exit;
}

$page_title = 'Number Invalid';
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
        .mark-invalid-page {
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
        
        .status-card {
            background: white;
            border: 2px solid #ef4444;
            border-radius: 12px;
            padding: 1.5rem 1rem;
            text-align: center;
            margin-bottom: 1rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }
        
        .status-icon {
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
        
        .status-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 0.5rem;
        }
        
        .status-desc {
            font-size: 0.875rem;
            color: #64748b;
            margin: 0;
        }
        
        .action-buttons {
            display: flex;
            gap: 0.75rem;
            margin-top: 1rem;
        }
        
        .action-buttons .btn {
            flex: 1;
            padding: 0.625rem 1rem;
            font-size: 0.9375rem;
            font-weight: 600;
        }
        
        .info-box {
            background: #f8fafc;
            border-left: 4px solid #0a6286;
            border-radius: 8px;
            padding: 0.875rem;
            margin-bottom: 1rem;
            font-size: 0.875rem;
            color: #475569;
        }
        
        @media (max-width: 767px) {
            .mark-invalid-page {
                padding: 0.5rem;
            }
            
            .content-title {
                font-size: 1.25rem;
            }
            
            .donor-header {
                padding: 0.875rem;
            }
            
            .status-card {
                padding: 1.25rem 1rem;
            }
            
            .status-icon {
                width: 60px;
                height: 60px;
                font-size: 1.75rem;
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
            <div class="mark-invalid-page">
                <div class="content-header mb-4">
                    <h1 class="content-title">
                        <i class="fas fa-times-circle me-2"></i>
                        Number Invalid
                    </h1>
                    <p class="content-subtitle">This number has been marked as invalid</p>
                </div>
                
                <div class="donor-header">
                    <h4><?php echo htmlspecialchars($donor->name); ?></h4>
                    <div class="phone">
                        <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($donor->phone); ?>
                    </div>
                </div>
                
                <div class="status-card">
                    <div class="status-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="status-title">Number Not Working</div>
                    <div class="status-desc">
                        This number has been marked as invalid and removed from the active queue.
                    </div>
                </div>
                
                <div class="info-box">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>What happened:</strong> The call attempt was recorded and the queue item has been marked as completed. 
                    This donor will no longer appear in the active call queue.
                </div>
                
                <div class="action-buttons">
                    <a href="index.php" class="btn btn-primary">
                        <i class="fas fa-arrow-left me-2"></i>Back to Queue
                    </a>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
</body>
</html>

