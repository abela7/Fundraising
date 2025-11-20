<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

try {
    $db = db();
    
    // Get parameters (these come from confirm-invalid.php redirect)
    $donor_id = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0;
    $queue_id = isset($_GET['queue_id']) ? (int)$_GET['queue_id'] : 0;
    $call_date = isset($_GET['call_date']) ? $_GET['call_date'] : date('Y-m-d');
    $call_time = isset($_GET['call_time']) ? $_GET['call_time'] : date('H:i:s');
    $agent_name = isset($_GET['agent']) ? $_GET['agent'] : 'Unknown';
    
    if (!$donor_id) {
        header('Location: ../donor-management/donors.php');
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
        header('Location: ../donor-management/donors.php');
        exit;
    }
    
} catch (Exception $e) {
    error_log("Mark Invalid Error: " . $e->getMessage());
    header('Location: ../donor-management/donors.php?error=1');
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
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="status-title">Number Marked as Invalid</div>
                    <div class="status-desc">
                        This number has been marked as invalid and removed from the active queue.
                    </div>
                </div>
                
                <div class="call-details">
                    <div class="call-details-title">
                        <i class="fas fa-calendar-check me-1"></i>Recorded Call Details
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Day of Call</span>
                        <span class="detail-value" id="recorded-date"><?php echo htmlspecialchars($call_date); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Time</span>
                        <span class="detail-value" id="recorded-time"><?php echo htmlspecialchars($call_time); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Agent</span>
                        <span class="detail-value"><?php echo htmlspecialchars($agent_name); ?></span>
                    </div>
                </div>
                
                <div class="info-box">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>What happened:</strong> The call attempt was recorded and the queue item has been marked as completed. 
                    This donor will no longer appear in the active call queue.
                </div>
                
                <div class="action-buttons">
                    <?php if($queue_id > 0): ?>
                    <a href="index.php" class="btn btn-primary">
                        <i class="fas fa-arrow-left me-2"></i>Back to Queue
                    </a>
                    <?php else: ?>
                    <a href="../donor-management/donors.php" class="btn btn-primary">
                        <i class="fas fa-arrow-left me-2"></i>Back to List
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
(function() {
    // Format the recorded date and time nicely
    const dateStr = <?php echo json_encode($call_date); ?>;
    const timeStr = <?php echo json_encode($call_time); ?>;
    
    if (dateStr && timeStr) {
        // Parse date and time
        const [year, month, day] = dateStr.split('-');
        const [hours, minutes] = timeStr.split(':');
        
        // Create date object (browser will use local timezone)
        const dateObj = new Date(parseInt(year), parseInt(month) - 1, parseInt(day), 
                                parseInt(hours), parseInt(minutes));
        
        // Format date
        const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        const months = ['January', 'February', 'March', 'April', 'May', 'June', 
                       'July', 'August', 'September', 'October', 'November', 'December'];
        const formattedDate = days[dateObj.getDay()] + ', ' + months[dateObj.getMonth()] + ' ' + 
                             dateObj.getDate() + ', ' + dateObj.getFullYear();
        
        // Format time
        let h = dateObj.getHours();
        const m = dateObj.getMinutes();
        const ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12;
        h = h ? h : 12;
        const formattedTime = h + ':' + String(m).padStart(2, '0') + ' ' + ampm;
        
        // Update display
        document.getElementById('recorded-date').textContent = formattedDate;
        document.getElementById('recorded-time').textContent = formattedTime;
    }
})();
</script>
</body>
</html>

