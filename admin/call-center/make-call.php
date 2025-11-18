<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

try {
    $db = db();
    $user_id = (int)$_SESSION['user']['id'];
    $user_name = $_SESSION['user']['name'] ?? 'Agent';
    
    // Get donor_id and queue_id from URL
    $donor_id = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0;
    $queue_id = isset($_GET['queue_id']) ? (int)$_GET['queue_id'] : 0;
    
    if (!$donor_id || !$queue_id) {
        header('Location: index.php');
        exit;
    }
    
    // Get donor information
    $donor_query = "
        SELECT 
            d.id,
            d.name,
            d.phone,
            d.balance,
            d.city,
            d.created_at,
            d.last_contacted_at,
            q.queue_type,
            q.priority,
            q.attempts_count,
            q.reason_for_queue,
            (SELECT name FROM users WHERE id = d.created_by LIMIT 1) as registrar_name,
            (SELECT call_started_at FROM call_center_sessions WHERE donor_id = d.id ORDER BY call_started_at DESC LIMIT 1) as last_call_date
        FROM donors d
        LEFT JOIN call_center_queues q ON q.donor_id = d.id AND q.id = ?
        WHERE d.id = ?
        LIMIT 1
    ";
    
    $stmt = $db->prepare($donor_query);
    $stmt->bind_param('ii', $queue_id, $donor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $donor = $result->fetch_object();
    $stmt->close();
    
    if (!$donor) {
        header('Location: index.php');
        exit;
    }
    
    // Check if this is truly a first call
    $call_history_query = "SELECT COUNT(*) as call_count FROM call_center_sessions WHERE donor_id = ?";
    $stmt = $db->prepare($call_history_query);
    $stmt->bind_param('i', $donor_id);
    $stmt->execute();
    $history = $stmt->get_result()->fetch_object();
    $stmt->close();
    
    $is_first_call = $history->call_count == 0;
    
    // Format last contact
    $last_contact = 'Never';
    if (!empty($donor->last_call_date)) {
        $last_contact = date('M j, Y g:i A', strtotime($donor->last_call_date));
    } elseif (!empty($donor->last_contacted_at)) {
        $last_contact = date('M j, Y', strtotime($donor->last_contacted_at));
    }
    
} catch (Exception $e) {
    error_log("Make Call Error: " . $e->getMessage());
    header('Location: index.php');
    exit;
}

$page_title = 'Call: ' . $donor->name;
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
        .call-prep-page {
            max-width: 700px;
            margin: 0 auto;
        }
        
        .donor-info-card {
            background: #f8fafc;
            border: 1px solid var(--cc-border);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--cc-border);
        }
        
        .info-row:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }
        
        .info-row:first-child {
            padding-top: 0;
        }
        
        .info-label {
            color: #64748b;
            font-size: 0.875rem;
            font-weight: 500;
        }
        
        .info-value {
            color: var(--cc-dark);
            font-weight: 600;
        }
        
        .pledge-highlight {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 12px;
            text-align: center;
            margin: 1.5rem 0;
        }
        
        .pledge-highlight .label {
            font-size: 0.875rem;
            opacity: 0.9;
            margin-bottom: 0.5rem;
        }
        
        .pledge-highlight .amount {
            font-size: 2.5rem;
            font-weight: 700;
        }
        
        .action-buttons-form {
            margin-top: 2rem;
        }
        
        .action-buttons {
            display: flex;
            gap: 1rem;
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
            <div class="call-prep-page">
                <div class="content-header mb-4">
                    <h1 class="content-title">
                        <i class="fas fa-phone-alt me-2"></i>
                        Ready to Call
                    </h1>
                    <p class="content-subtitle">Review donor information before starting the call</p>
                </div>
                
                <?php if ($is_first_call): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>First Time Call</strong> - This is the first contact with this donor.
                    </div>
                <?php endif; ?>
                
                <div class="donor-info-card">
                    <div class="info-row">
                        <span class="info-label">Donor Name</span>
                        <span class="info-value"><?php echo htmlspecialchars($donor->name); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Phone Number</span>
                        <a href="tel:<?php echo htmlspecialchars($donor->phone); ?>" class="info-value text-decoration-none">
                            <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($donor->phone); ?>
                        </a>
                    </div>
                    <?php if (!empty($donor->city)): ?>
                    <div class="info-row">
                        <span class="info-label">Location</span>
                        <span class="info-value"><?php echo htmlspecialchars($donor->city); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="info-row">
                        <span class="info-label">Call Attempts</span>
                        <span class="info-value">
                            <span class="badge bg-info"><?php echo (int)$donor->attempts_count; ?> calls</span>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Last Contact</span>
                        <span class="info-value"><?php echo htmlspecialchars($last_contact); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Registered By</span>
                        <span class="info-value"><?php echo htmlspecialchars($donor->registrar_name ?? 'Unknown'); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Call Date & Time</span>
                        <span class="info-value"><?php echo date('l, F j, Y - g:i A'); ?></span>
                    </div>
                </div>
                
                <div class="pledge-highlight">
                    <div class="label">Pledged Amount</div>
                    <div class="amount">£<?php echo number_format((float)$donor->balance, 2); ?></div>
                </div>
                
                <form method="POST" action="call-status.php" class="action-buttons-form">
                    <input type="hidden" name="donor_id" value="<?php echo $donor_id; ?>">
                    <input type="hidden" name="queue_id" value="<?php echo $queue_id; ?>">
                    <div class="action-buttons">
                        <a href="index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Back to Queue
                        </a>
                        <button type="submit" class="btn btn-success btn-lg">
                            <i class="fas fa-phone-alt me-2"></i>Start Call
                        </button>
                    </div>
                </form>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
</body>
</html>
