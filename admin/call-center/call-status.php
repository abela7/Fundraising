<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

try {
    $db = db();
    $user_id = (int)$_SESSION['user']['id'];
    
    // Get donor_id and queue_id from POST (form submission) or GET (direct access)
    $donor_id = isset($_POST['donor_id']) ? (int)$_POST['donor_id'] : (isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0);
    $queue_id = isset($_POST['queue_id']) ? (int)$_POST['queue_id'] : (isset($_GET['queue_id']) ? (int)$_GET['queue_id'] : 0);
    
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
    
    // Record call start time in database
    $call_started_at = date('Y-m-d H:i:s');
    
    // Create a new call session record
    // Note: conversation_stage starts as 'no_connection' until we know if they picked up
    $session_query = "
        INSERT INTO call_center_sessions 
        (donor_id, agent_id, call_started_at, conversation_stage, created_at)
        VALUES (?, ?, ?, 'no_connection', NOW())
    ";
    
    $session_id = 0;
    $stmt = $db->prepare($session_query);
    if ($stmt) {
        $stmt->bind_param('iis', $donor_id, $user_id, $call_started_at);
        $stmt->execute();
        $session_id = $db->insert_id;
        $stmt->close();
        
        // Update queue attempts count and last attempt time
        $update_queue = "UPDATE call_center_queues 
                        SET attempts_count = attempts_count + 1, 
                            last_attempt_at = NOW(),
                            status = 'in_progress'
                        WHERE id = ?";
        $stmt = $db->prepare($update_queue);
        if ($stmt) {
            $stmt->bind_param('i', $queue_id);
            $stmt->execute();
            $stmt->close();
        }
    }
    
} catch (Exception $e) {
    error_log("Call Status Error: " . $e->getMessage());
    header('Location: index.php');
    exit;
}

$page_title = 'Call Status';
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
        .call-status-page {
            max-width: 700px;
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
        
        .option-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.875rem;
            margin: 1rem 0;
        }
        
        .option-card {
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 1.25rem 1rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.25s;
            text-decoration: none;
            color: inherit;
            display: block;
        }
        
        .option-card:hover {
            border-color: #0a6286;
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            text-decoration: none;
            color: inherit;
        }
        
        .option-card.success:hover {
            border-color: #22c55e;
        }
        
        .option-card.danger:hover {
            border-color: #ef4444;
        }
        
        .option-icon {
            width: 55px;
            height: 55px;
            border-radius: 50%;
            background: #f0f9ff;
            color: #0a6286;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.75rem;
            font-size: 1.5rem;
        }
        
        .option-card.danger .option-icon {
            background: #fef2f2;
            color: #ef4444;
        }
        
        .option-card.success .option-icon {
            background: #f0fdf4;
            color: #22c55e;
        }
        
        .option-title {
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            color: #1e293b;
        }
        
        .option-desc {
            font-size: 0.8125rem;
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
        
        @media (max-width: 767px) {
            .call-status-page {
                padding: 0.5rem;
            }
            
            .content-title {
                font-size: 1.25rem;
            }
            
            .donor-header {
                padding: 0.875rem;
            }
            
            .option-grid {
                grid-template-columns: 1fr;
                gap: 0.75rem;
            }
            
            .option-card {
                padding: 1.125rem 1rem;
            }
            
            .option-icon {
                width: 50px;
                height: 50px;
                font-size: 1.375rem;
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
            <div class="call-status-page">
                <div class="content-header mb-4">
                    <h1 class="content-title">
                        <i class="fas fa-phone-volume me-2"></i>
                        Phone Status
                    </h1>
                    <p class="content-subtitle">What happened when you called?</p>
                </div>
                
                <div class="donor-header">
                    <h4><?php echo htmlspecialchars($donor->name); ?></h4>
                    <div class="phone">
                        <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($donor->phone); ?>
                    </div>
                </div>
                
                <div class="option-grid">
                    <?php 
                    $session_param = $session_id > 0 ? "session_id={$session_id}&" : '';
                    ?>
                    <a href="availability-check.php?<?php echo $session_param; ?>donor_id=<?php echo $donor_id; ?>&queue_id=<?php echo $queue_id; ?>&status=picked_up" 
                       class="option-card success">
                        <div class="option-icon">
                            <i class="fas fa-phone-alt"></i>
                        </div>
                        <div class="option-title">Picked Up</div>
                        <div class="option-desc">Donor answered the call</div>
                    </a>
                    
                    <a href="schedule-callback.php?<?php echo $session_param; ?>donor_id=<?php echo $donor_id; ?>&queue_id=<?php echo $queue_id; ?>&status=not_picked_up" 
                       class="option-card danger">
                        <div class="option-icon">
                            <i class="fas fa-phone-slash"></i>
                        </div>
                        <div class="option-title">No Answer</div>
                        <div class="option-desc">Donor didn't pick up</div>
                    </a>
                    
                    <a href="schedule-callback.php?<?php echo $session_param; ?>donor_id=<?php echo $donor_id; ?>&queue_id=<?php echo $queue_id; ?>&status=busy" 
                       class="option-card danger">
                        <div class="option-icon">
                            <i class="fas fa-ban"></i>
                        </div>
                        <div class="option-title">Busy</div>
                        <div class="option-desc">Line was busy</div>
                    </a>
                    
                    <a href="mark-invalid.php?<?php echo $session_param; ?>donor_id=<?php echo $donor_id; ?>&queue_id=<?php echo $queue_id; ?>&reason=not_working" 
                       class="option-card danger">
                        <div class="option-icon">
                            <i class="fas fa-times-circle"></i>
                        </div>
                        <div class="option-title">Not Working</div>
                        <div class="option-desc">Number not reachable</div>
                    </a>
                </div>
                
                <div class="action-buttons">
                    <a href="make-call.php?donor_id=<?php echo $donor_id; ?>&queue_id=<?php echo $queue_id; ?>" 
                       class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back
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

