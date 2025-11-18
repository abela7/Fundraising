<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

try {
    $db = db();
    $user_id = (int)$_SESSION['user']['id'];
    
    // Get donor_id and queue_id from URL
    $donor_id = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0;
    $queue_id = isset($_GET['queue_id']) ? (int)$_GET['queue_id'] : 0;
    
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
    
    // Record call start time
    $call_started_at = date('Y-m-d H:i:s');
    
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
            max-width: 800px;
            margin: 0 auto;
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
            grid-template-columns: repeat(2, 1fr);
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
        
        .option-card.danger .option-icon {
            background: #fee2e2;
            color: var(--cc-danger);
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
        
        @media (max-width: 767px) {
            .option-grid {
                grid-template-columns: 1fr;
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
                    <a href="availability-check.php?donor_id=<?php echo $donor_id; ?>&queue_id=<?php echo $queue_id; ?>&status=picked_up" 
                       class="option-card success">
                        <div class="option-icon">
                            <i class="fas fa-phone-alt"></i>
                        </div>
                        <div class="option-title">Picked Up</div>
                        <div class="option-desc">Donor answered the call</div>
                    </a>
                    
                    <a href="schedule-callback.php?donor_id=<?php echo $donor_id; ?>&queue_id=<?php echo $queue_id; ?>&status=not_picked_up" 
                       class="option-card danger">
                        <div class="option-icon">
                            <i class="fas fa-phone-slash"></i>
                        </div>
                        <div class="option-title">No Answer</div>
                        <div class="option-desc">Donor didn't pick up</div>
                    </a>
                    
                    <a href="schedule-callback.php?donor_id=<?php echo $donor_id; ?>&queue_id=<?php echo $queue_id; ?>&status=busy" 
                       class="option-card danger">
                        <div class="option-icon">
                            <i class="fas fa-ban"></i>
                        </div>
                        <div class="option-title">Busy</div>
                        <div class="option-desc">Line was busy</div>
                    </a>
                    
                    <a href="schedule-callback.php?donor_id=<?php echo $donor_id; ?>&queue_id=<?php echo $queue_id; ?>&status=not_working" 
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

