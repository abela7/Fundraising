<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

try {
    $db = db();
    $user_id = (int)$_SESSION['user']['id'];
    
    // Get parameters from URL
    $session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
    $donor_id = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0;
    $queue_id = isset($_GET['queue_id']) ? (int)$_GET['queue_id'] : 0;
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    $call_started_at = isset($_GET['call_started_at']) ? urldecode($_GET['call_started_at']) : gmdate('Y-m-d H:i:s');
    
    if (!$donor_id || $status !== 'picked_up') {
        header('Location: ../donor-management/donors.php');
        exit;
    }
    
    // Create session if not exists (Lazy creation)
    // Only on GET request and redirect immediately to persist state
    if ($session_id <= 0 && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $session_query = "
            INSERT INTO call_center_sessions 
            (donor_id, agent_id, call_started_at, conversation_stage, created_at)
            VALUES (?, ?, ?, 'connected_no_identity_check', NOW())
        ";
        
        $stmt = $db->prepare($session_query);
        if ($stmt) {
            $stmt->bind_param('iis', $donor_id, $user_id, $call_started_at);
            $stmt->execute();
            $session_id = $db->insert_id;
            $stmt->close();
            
            // Update queue attempts count and last attempt time
            if ($queue_id > 0) {
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
            
            // REDIRECT to self with session_id
            $query_params = $_GET;
            $query_params['session_id'] = $session_id;
            $new_url = 'availability-check.php?' . http_build_query($query_params);
            header("Location: " . $new_url);
            exit;
        }
    }
    
    // Get donor name and details for display and widget
    $donor_query = "
        SELECT d.name, d.phone, d.balance, d.city,
               COALESCE(p.amount, 0) as pledge_amount, 
               p.created_at as pledge_date,
               c.name as church_name,
               COALESCE(
                    (SELECT name FROM users WHERE id = d.registered_by_user_id LIMIT 1),
                    (SELECT u.name FROM pledges p2 JOIN users u ON p2.created_by_user_id = u.id WHERE p2.donor_id = d.id ORDER BY p2.created_at DESC LIMIT 1),
                    (SELECT u.name FROM payments pay JOIN users u ON pay.received_by_user_id = u.id WHERE pay.donor_id = d.id ORDER BY pay.created_at DESC LIMIT 1),
                    'Unknown'
                ) as registrar_name
        FROM donors d
        LEFT JOIN pledges p ON d.id = p.donor_id AND p.status = 'approved'
        LEFT JOIN churches c ON d.church_id = c.id
        WHERE d.id = ? 
        ORDER BY p.created_at DESC 
        LIMIT 1
    ";
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
    
} catch (Exception $e) {
    error_log("Availability Check Error: " . $e->getMessage());
    header('Location: ../donor-management/donors.php');
    exit;
}

$page_title = 'Donor Availability';
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
    <link rel="stylesheet" href="assets/call-widget.css">
    <style>
        .availability-page {
            max-width: 700px;
            margin: 0 auto;
            padding-top: 20px;
        }
        
        .donor-header {
            background: #f8fafc;
            border: 1px solid var(--cc-border);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            text-align: center;
        }
        
        .donor-header h4 {
            margin: 0 0 0.5rem 0;
            color: var(--cc-dark);
        }
        
        .donor-header .phone {
            color: #64748b;
            font-size: 0.9375rem;
        }
        
        .option-grid {
            display: grid;
            gap: 1rem;
            margin: 2rem 0;
        }
        
        .option-card {
            background: white;
            border: 2px solid var(--cc-border);
            border-radius: 12px;
            padding: 2rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            color: inherit;
            display: block;
        }
        
        .option-card:hover {
            border-color: var(--cc-primary);
            transform: translateY(-2px);
            box-shadow: var(--cc-shadow-lg);
            text-decoration: none;
            color: inherit;
            background: #f8fafc;
        }
        
        .option-card.success {
            border-color: var(--cc-success);
        }
        
        .option-card.success:hover {
            border-color: var(--cc-success);
            background: #f0fdf4;
        }
        
        .option-icon {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: #f0f9ff;
            color: var(--cc-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 2rem;
        }
        
        .option-card.success .option-icon {
            background: #d1fae5;
            color: var(--cc-success);
        }
        
        .option-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .option-desc {
            font-size: 0.875rem;
            color: #64748b;
        }
        
        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
        }
        
        .action-buttons .btn {
            flex: 1;
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="availability-page">
                <div class="content-header mb-4">
                    <h1 class="content-title">
                        <i class="fas fa-comments me-2"></i>
                        Donor Availability
                    </h1>
                    <p class="content-subtitle">Can the donor talk right now?</p>
                </div>
                
                <div class="donor-header">
                    <h4><?php echo htmlspecialchars($donor->name); ?></h4>
                    <div class="phone">
                        <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($donor->phone); ?>
                    </div>
                </div>
                
                <div class="option-grid">
                    <a href="conversation.php?<?php echo $session_id > 0 ? "session_id={$session_id}&" : ''; ?>donor_id=<?php echo $donor_id; ?>&queue_id=<?php echo $queue_id; ?>" 
                       class="option-card success">
                        <div class="option-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="option-title">Can Talk</div>
                        <div class="option-desc">Donor is available to talk now</div>
                    </a>
                    
                    <a href="callback-reason.php?<?php echo $session_id > 0 ? "session_id={$session_id}&" : ''; ?>donor_id=<?php echo $donor_id; ?>&queue_id=<?php echo $queue_id; ?>" 
                       class="option-card">
                        <div class="option-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="option-title">Can't Talk Now</div>
                        <div class="option-desc">Donor is busy, schedule callback</div>
                    </a>
                </div>
                
                <div class="action-buttons">
                    <a href="call-status.php?donor_id=<?php echo $donor_id; ?>&queue_id=<?php echo $queue_id; ?>" 
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
<script src="assets/call-widget.js"></script>
<script>
    // Initialize Call Widget
    document.addEventListener('DOMContentLoaded', function() {
        CallWidget.init({
            sessionId: <?php echo $session_id; ?>,
            donorId: <?php echo $donor_id; ?>,
            donorName: '<?php echo addslashes($donor->name); ?>',
            donorPhone: '<?php echo addslashes($donor->phone); ?>',
            pledgeAmount: <?php echo $donor->pledge_amount; ?>,
            pledgeDate: '<?php echo $donor->pledge_date ? date('M j, Y', strtotime($donor->pledge_date)) : 'Unknown'; ?>',
            registrar: '<?php echo addslashes($donor->registrar_name); ?>',
            church: '<?php echo addslashes($donor->church_name ?? $donor->city ?? 'Unknown'); ?>'
        });
        
        // Auto-start timer since status is 'picked_up'
        CallWidget.start();
    });
</script>
</body>
</html>
