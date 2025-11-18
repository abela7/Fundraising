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
        :root {
            --prep-primary: #2563eb;
            --prep-success: #10b981;
            --prep-danger: #ef4444;
            --prep-bg: #f8fafc;
            --prep-border: #e2e8f0;
            --prep-text: #1e293b;
            --prep-text-muted: #64748b;
            --prep-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            --prep-shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }

        .call-prep-page {
            max-width: 800px;
            margin: 0 auto;
            padding: 1rem;
        }

        .content-header {
            margin-bottom: 2rem;
            text-align: center;
        }

        .content-header .content-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--prep-text);
            margin-bottom: 0.5rem;
        }

        .content-header .content-subtitle {
            color: var(--prep-text-muted);
            font-size: 0.9375rem;
            margin-bottom: 0;
        }

        .alert-info {
            border-left: 4px solid var(--prep-primary);
            background: #eff6ff;
            border-color: var(--prep-primary);
            margin-bottom: 1.5rem;
        }

        .donor-info-card {
            background: white;
            border: 1px solid var(--prep-border);
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--prep-shadow);
        }

        .donor-header-section {
            text-align: center;
            padding-bottom: 1.5rem;
            border-bottom: 2px solid var(--prep-border);
            margin-bottom: 1.5rem;
        }

        .donor-name-large {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--prep-text);
            margin-bottom: 0.5rem;
        }

        .donor-phone-large {
            font-size: 1.125rem;
            color: var(--prep-text-muted);
        }

        .donor-phone-large a {
            color: var(--prep-primary);
            text-decoration: none;
            font-weight: 600;
        }

        .donor-phone-large a:hover {
            text-decoration: underline;
        }

        .info-row {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            padding: 1rem 0;
            border-bottom: 1px solid var(--prep-border);
        }

        .info-row:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .info-row:first-child {
            padding-top: 0;
        }

        .info-label {
            color: var(--prep-text-muted);
            font-size: 0.8125rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-value {
            color: var(--prep-text);
            font-weight: 600;
            font-size: 0.9375rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .info-value .badge {
            font-size: 0.8125rem;
            padding: 0.25rem 0.75rem;
        }

        .info-value .text-muted {
            color: var(--prep-text-muted) !important;
            font-weight: 400;
            font-size: 0.875rem;
        }

        .info-label i {
            width: 18px;
            text-align: center;
            color: var(--prep-primary);
        }

        .pledge-highlight {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
            padding: 2rem 1.5rem;
            border-radius: 16px;
            text-align: center;
            margin: 2rem 0;
            box-shadow: var(--prep-shadow-lg);
        }

        .pledge-highlight .label {
            font-size: 0.875rem;
            opacity: 0.95;
            margin-bottom: 0.75rem;
            font-weight: 500;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .pledge-highlight .amount {
            font-size: 3rem;
            font-weight: 700;
            line-height: 1;
        }

        .action-buttons-form {
            margin-top: 2rem;
            position: sticky;
            bottom: 0;
            background: white;
            padding: 1rem 0;
            border-top: 1px solid var(--prep-border);
        }

        .action-buttons {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .action-buttons .btn {
            padding: 0.875rem 1.5rem;
            font-size: 1rem;
            font-weight: 600;
            border-radius: 12px;
            transition: all 0.2s;
        }

        .action-buttons .btn-success {
            background: var(--prep-success);
            border-color: var(--prep-success);
            box-shadow: 0 4px 6px -1px rgba(16, 185, 129, 0.3);
        }

        .action-buttons .btn-success:hover {
            background: #059669;
            transform: translateY(-1px);
            box-shadow: 0 6px 8px -1px rgba(16, 185, 129, 0.4);
        }

        .action-buttons .btn-outline-secondary {
            border-width: 2px;
        }

        .action-buttons .btn-outline-secondary:hover {
            background: #f1f5f9;
        }

        /* Extra Small Screens */
        @media (max-width: 575px) {
            .action-buttons .btn {
                min-height: 48px; /* Better touch target */
            }

            .pledge-highlight .amount {
                font-size: 2.5rem;
            }

            .donor-name-large {
                font-size: 1.25rem;
            }
        }

        /* Mobile First - Base Styles */
        @media (min-width: 576px) {
            .call-prep-page {
                padding: 1.5rem;
            }

            .content-header .content-title {
                font-size: 2rem;
            }

            .donor-name-large {
                font-size: 1.75rem;
            }

            .info-row {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }

            .info-label {
                font-size: 0.875rem;
            }

            .info-value {
                font-size: 1rem;
                text-align: right;
            }

            .pledge-highlight {
                padding: 2.5rem 2rem;
            }

            .pledge-highlight .amount {
                font-size: 3.5rem;
            }

            .action-buttons {
                flex-direction: row;
            }

            .action-buttons .btn {
                flex: 1;
            }
        }

        @media (min-width: 768px) {
            .call-prep-page {
                padding: 2rem;
            }

            .donor-info-card {
                padding: 2rem;
            }

            .pledge-highlight {
                padding: 3rem 2.5rem;
            }

            .pledge-highlight .amount {
                font-size: 4rem;
            }

            .action-buttons-form {
                position: relative;
                padding: 0;
                border-top: none;
            }
        }

        @media (min-width: 992px) {
            .content-header {
                text-align: left;
            }

            .donor-header-section {
                text-align: left;
            }
        }

        /* Print Styles */
        @media print {
            .action-buttons-form {
                display: none;
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
                    <!-- Donor Header Section -->
                    <div class="donor-header-section">
                        <div class="donor-name-large">
                            <i class="fas fa-user-circle me-2" style="color: var(--prep-primary);"></i>
                            <?php echo htmlspecialchars($donor->name); ?>
                        </div>
                        <div class="donor-phone-large">
                            <a href="tel:<?php echo htmlspecialchars($donor->phone); ?>">
                                <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($donor->phone); ?>
                            </a>
                        </div>
                    </div>

                    <!-- Information Rows -->
                    <?php if (!empty($donor->city)): ?>
                    <div class="info-row">
                        <span class="info-label">
                            <i class="fas fa-map-marker-alt me-1"></i>Location
                        </span>
                        <span class="info-value"><?php echo htmlspecialchars($donor->city); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="info-row">
                        <span class="info-label">
                            <i class="fas fa-redo me-1"></i>Call Attempts
                        </span>
                        <span class="info-value">
                            <span class="badge bg-info"><?php echo (int)$donor->attempts_count; ?> calls</span>
                        </span>
                    </div>
                    
                    <div class="info-row">
                        <span class="info-label">
                            <i class="fas fa-clock me-1"></i>Last Contact
                        </span>
                        <span class="info-value"><?php echo htmlspecialchars($last_contact); ?></span>
                    </div>
                    
                    <div class="info-row">
                        <span class="info-label">
                            <i class="fas fa-user-tie me-1"></i>Registered By
                        </span>
                        <span class="info-value">
                            <?php 
                            if (!empty($donor->registrar_name)) {
                                echo htmlspecialchars($donor->registrar_name);
                                if (!empty($donor->pledge_date)) {
                                    echo ' <small class="text-muted">(Pledge: ' . date('M j, Y', strtotime($donor->pledge_date)) . ')</small>';
                                }
                            } else {
                                echo '<span class="text-danger">Not found</span>';
                            }
                            ?>
                        </span>
                    </div>
                    
                    <div class="info-row">
                        <span class="info-label">
                            <i class="fas fa-calendar me-1"></i>Registration Date
                        </span>
                        <span class="info-value"><?php echo date('M j, Y', strtotime($donor->created_at)); ?></span>
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
                            <i class="fas fa-arrow-left me-2"></i><span>Back to Queue</span>
                        </a>
                        <button type="submit" class="btn btn-success btn-lg">
                            <i class="fas fa-phone-alt me-2"></i><span>Start Call</span>
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
