<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

// Set timezone to London
date_default_timezone_set('Europe/London');

try {
    $db = db();
    $user_id = (int)$_SESSION['user']['id'];
    
    // Get parameters
    $session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
    $donor_id = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0;
    $queue_id = isset($_GET['queue_id']) ? (int)$_GET['queue_id'] : 0;
    
    if (!$donor_id || !$queue_id) {
        header('Location: index.php');
        exit;
    }
    
    // Get donor info for widget and display
    $donor_query = "
        SELECT d.name, d.phone, d.balance, d.city,
               COALESCE(p.amount, 0) as pledge_amount, 
               p.created_at as pledge_date,
               c.name as church_name,
               COALESCE(
                    (SELECT name FROM users WHERE id = d.registered_by_user_id LIMIT 1),
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
    error_log("Callback Reason Error: " . $e->getMessage());
    header('Location: index.php');
    exit;
}

$page_title = 'Reason for Callback';
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
        .reason-page {
            max-width: 800px;
            margin: 0 auto;
            padding-top: 20px;
        }
        
        .reason-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin: 1.5rem 0;
        }
        
        .reason-card {
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 1.25rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        
        .reason-card:hover {
            border-color: #0a6286;
            background: #f0f9ff;
            transform: translateY(-2px);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            color: inherit;
        }
        
        .reason-icon {
            font-size: 1.75rem;
            margin-bottom: 0.75rem;
            color: #64748b;
        }
        
        .reason-card:hover .reason-icon {
            color: #0a6286;
        }
        
        .reason-label {
            font-weight: 600;
            font-size: 1rem;
            color: #1e293b;
        }
        
        .donor-header {
            background: #0a6286;
            color: white;
            padding: 1.25rem 1rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            text-align: center;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        
        @media (max-width: 576px) {
            .reason-grid {
                grid-template-columns: 1fr;
            }
            
            .reason-card {
                flex-direction: row;
                justify-content: flex-start;
                padding: 1rem;
                text-align: left;
            }
            
            .reason-icon {
                margin-bottom: 0;
                margin-right: 1rem;
                font-size: 1.5rem;
                width: 40px;
                text-align: center;
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
            <div class="reason-page">
                <div class="content-header mb-4">
                    <h1 class="content-title">
                        <i class="fas fa-question-circle me-2"></i>
                        Reason for Callback
                    </h1>
                    <p class="content-subtitle">Why can't the donor talk right now?</p>
                </div>
                
                <div class="donor-header">
                    <h4 class="mb-1"><?php echo htmlspecialchars($donor->name); ?></h4>
                    <div class="phone">
                        <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($donor->phone); ?>
                    </div>
                </div>
                
                <?php 
                $base_url = "schedule-callback.php?session_id={$session_id}&donor_id={$donor_id}&queue_id={$queue_id}&status=busy_cant_talk&reason=";
                ?>
                
                <div class="reason-grid">
                    <a href="<?php echo $base_url . 'driving'; ?>" class="reason-card">
                        <div class="reason-icon"><i class="fas fa-car"></i></div>
                        <div class="reason-label">Driving</div>
                    </a>
                    
                    <a href="<?php echo $base_url . 'at_work'; ?>" class="reason-card">
                        <div class="reason-icon"><i class="fas fa-briefcase"></i></div>
                        <div class="reason-label">At Work</div>
                    </a>
                    
                    <a href="<?php echo $base_url . 'eating'; ?>" class="reason-card">
                        <div class="reason-icon"><i class="fas fa-utensils"></i></div>
                        <div class="reason-label">Eating / Meal</div>
                    </a>
                    
                    <a href="<?php echo $base_url . 'with_family'; ?>" class="reason-card">
                        <div class="reason-icon"><i class="fas fa-users"></i></div>
                        <div class="reason-label">With Family</div>
                    </a>
                    
                    <a href="<?php echo $base_url . 'sleeping'; ?>" class="reason-card">
                        <div class="reason-icon"><i class="fas fa-bed"></i></div>
                        <div class="reason-label">Sleeping / Resting</div>
                    </a>
                    
                    <a href="<?php echo $base_url . 'bad_time'; ?>" class="reason-card">
                        <div class="reason-icon"><i class="fas fa-clock"></i></div>
                        <div class="reason-label">Bad Time (General)</div>
                    </a>
                    
                    <a href="<?php echo $base_url . 'requested_later'; ?>" class="reason-card">
                        <div class="reason-icon"><i class="fas fa-calendar-alt"></i></div>
                        <div class="reason-label">Just Requested Later</div>
                    </a>
                    
                    <a href="<?php echo $base_url . 'other'; ?>" class="reason-card">
                        <div class="reason-icon"><i class="fas fa-ellipsis-h"></i></div>
                        <div class="reason-label">Other</div>
                    </a>
                </div>
                
                <div class="action-buttons mt-4">
                    <a href="availability-check.php?session_id=<?php echo $session_id; ?>&donor_id=<?php echo $donor_id; ?>&queue_id=<?php echo $queue_id; ?>&status=picked_up" 
                       class="btn btn-outline-secondary w-100">
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
    });
</script>
</body>
</html>
