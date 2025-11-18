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
            COALESCE(
                (SELECT name FROM users WHERE id = d.registered_by_user_id LIMIT 1),
                (SELECT u.name FROM pledges p 
                 JOIN users u ON p.created_by_user_id = u.id 
                 WHERE p.donor_id = d.id 
                 ORDER BY p.created_at DESC LIMIT 1),
                (SELECT u.name FROM payments pay 
                 JOIN users u ON pay.received_by_user_id = u.id 
                 WHERE pay.donor_id = d.id 
                 ORDER BY pay.created_at DESC LIMIT 1)
            ) as registrar_name,
            (SELECT created_at FROM pledges WHERE donor_id = d.id AND status = 'approved' ORDER BY created_at DESC LIMIT 1) as pledge_date,
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
    error_log("Make Call Error: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine());
    // Show error instead of redirecting silently
    die("Error loading donor information: " . htmlspecialchars($e->getMessage()) . ". <a href='index.php'>Go back</a>");
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
        /* Pre-Call Briefing - Modern Design */
        .call-prep-page {
            max-width: 800px;
            margin: 0 auto;
            padding: 0;
        }
        
        /* Donor Name Header - Compact */
        .donor-name-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1.25rem 1rem;
            border-radius: 12px;
            margin-bottom: 1rem;
            text-align: center;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.25);
        }
        
        .donor-name-header h2 {
            font-size: 1.25rem;
            font-weight: 700;
            margin: 0 0 0.5rem 0;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        
        .donor-name-header .phone-link {
            color: white;
            text-decoration: none;
            font-size: 0.9375rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.375rem 0.875rem;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 6px;
            transition: all 0.2s;
        }
        
        .donor-name-header .phone-link:hover {
            background: rgba(255, 255, 255, 0.3);
        }
        
        /* Info Grid - Compact */
        .info-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }
        
        .info-card {
            background: white;
            border: 1px solid var(--cc-border);
            border-radius: 10px;
            padding: 0.875rem;
            transition: all 0.2s;
            box-shadow: var(--cc-shadow);
        }
        
        .info-card:hover {
            box-shadow: var(--cc-shadow-lg);
        }
        
        .info-card-header {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--cc-border);
        }
        
        .info-card-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }
        
        .info-card-icon.primary {
            background: #eff6ff;
            color: var(--cc-primary);
        }
        
        .info-card-icon.success {
            background: #d1fae5;
            color: var(--cc-success);
        }
        
        .info-card-icon.warning {
            background: #fef3c7;
            color: var(--cc-warning);
        }
        
        .info-card-title {
            font-size: 0.75rem;
            font-weight: 600;
            color: #64748b;
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .info-card-body {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.25rem 0;
        }
        
        .info-label {
            color: #64748b;
            font-size: 0.8125rem;
            font-weight: 500;
        }
        
        .info-value {
            color: var(--cc-dark);
            font-weight: 600;
            font-size: 0.875rem;
            text-align: right;
        }
        
        /* Pledge Highlight - Compact */
        .pledge-highlight {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
            padding: 1.25rem 1rem;
            border-radius: 12px;
            text-align: center;
            margin: 1rem 0;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.25);
        }
        
        .pledge-highlight .label {
            font-size: 0.75rem;
            opacity: 0.95;
            margin-bottom: 0.5rem;
            font-weight: 600;
            letter-spacing: 1px;
            text-transform: uppercase;
        }
        
        .pledge-highlight .amount {
            font-size: 2rem;
            font-weight: 800;
            line-height: 1;
        }
        
        /* Action Buttons - Compact */
        .action-buttons-form {
            margin-top: 1.25rem;
        }
        
        .action-buttons {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.625rem;
        }
        
        .action-buttons .btn {
            padding: 0.75rem 1.25rem;
            font-size: 0.9375rem;
            font-weight: 600;
            border-radius: 10px;
            transition: all 0.2s;
            border-width: 2px;
        }
        
        .action-buttons .btn-success {
            background: var(--cc-success);
            border-color: var(--cc-success);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }
        
        .action-buttons .btn-success:hover {
            background: #059669;
            border-color: #059669;
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(16, 185, 129, 0.4);
        }
        
        .action-buttons .btn-outline-secondary {
            background: white;
            border-color: var(--cc-border);
            color: var(--cc-dark);
        }
        
        .action-buttons .btn-outline-secondary:hover {
            background: var(--cc-light);
            border-color: #cbd5e1;
        }
        
        /* Tablet - 2 columns for info cards */
        @media (min-width: 576px) {
            .donor-name-header h2 {
                font-size: 1.5rem;
            }
            
            .info-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .pledge-highlight .amount {
                font-size: 2.5rem;
            }
            
            .action-buttons {
                grid-template-columns: auto 1fr;
            }
            
            .action-buttons .btn:first-child {
                min-width: 120px;
            }
        }
        
        /* Desktop */
        @media (min-width: 768px) {
            .donor-name-header {
                padding: 1.5rem 1.25rem;
            }
            
            .donor-name-header h2 {
                font-size: 1.625rem;
            }
            
            .info-card {
                padding: 1rem;
            }
            
            .info-card-icon {
                width: 36px;
                height: 36px;
                font-size: 1.125rem;
            }
            
            .pledge-highlight {
                padding: 1.5rem 1.25rem;
            }
            
            .pledge-highlight .amount {
                font-size: 2.75rem;
            }
        }
        
        /* Large Desktop */
        @media (min-width: 992px) {
            .info-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        
        /* First Call Badge - Compact */
        .first-call-badge {
            display: inline-block;
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.8125rem;
            font-weight: 600;
            margin-bottom: 0.875rem;
            box-shadow: 0 2px 8px rgba(37, 99, 235, 0.25);
        }
        
        .first-call-badge i {
            margin-right: 0.375rem;
            font-size: 0.75rem;
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
                <?php if ($is_first_call): ?>
                    <div class="first-call-badge">
                        <i class="fas fa-star"></i>
                        First Time Call - This is the first contact with this donor
                    </div>
                <?php endif; ?>
                
                <!-- Donor Name Header -->
                <div class="donor-name-header">
                    <h2>
                        <i class="fas fa-user-circle"></i>
                        <?php echo htmlspecialchars($donor->name); ?>
                    </h2>
                    <a href="tel:<?php echo htmlspecialchars($donor->phone); ?>" class="phone-link">
                        <i class="fas fa-phone"></i>
                        <?php echo htmlspecialchars($donor->phone); ?>
                    </a>
                </div>
                
                <!-- Info Grid -->
                <div class="info-grid">
                    <!-- Contact History Card -->
                    <div class="info-card">
                        <div class="info-card-header">
                            <div class="info-card-icon primary">
                                <i class="fas fa-history"></i>
                            </div>
                            <h3 class="info-card-title">Contact History</h3>
                        </div>
                        <div class="info-card-body">
                            <div class="info-item">
                                <span class="info-label">Attempts</span>
                                <span class="badge bg-info"><?php echo (int)$donor->attempts_count; ?> calls</span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Last Contact</span>
                                <span class="info-value"><?php echo htmlspecialchars($last_contact); ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Location Card -->
                    <div class="info-card">
                        <div class="info-card-header">
                            <div class="info-card-icon success">
                                <i class="fas fa-map-marker-alt"></i>
                            </div>
                            <h3 class="info-card-title">Location</h3>
                        </div>
                        <div class="info-card-body">
                            <div class="info-item">
                                <span class="info-label">City</span>
                                <span class="info-value"><?php echo !empty($donor->city) ? htmlspecialchars($donor->city) : '<span class="text-muted">Not specified</span>'; ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Registration Card -->
                    <div class="info-card">
                        <div class="info-card-header">
                            <div class="info-card-icon warning">
                                <i class="fas fa-user-tag"></i>
                            </div>
                            <h3 class="info-card-title">Registration</h3>
                        </div>
                        <div class="info-card-body">
                            <div class="info-item">
                                <span class="info-label">Registered By</span>
                                <span class="info-value">
                                    <?php 
                                    if (!empty($donor->registrar_name)) {
                                        echo htmlspecialchars($donor->registrar_name);
                                    } else {
                                        echo '<span class="text-danger" style="font-size: 0.75rem;">Not found</span>';
                                    }
                                    ?>
                                </span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Date</span>
                                <span class="info-value"><?php echo date('M j, Y', strtotime($donor->created_at)); ?></span>
                            </div>
                        </div>
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
