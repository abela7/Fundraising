<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../services/SMSHelper.php';
require_once __DIR__ . '/../../services/MessagingHelper.php';
require_login();

// Set timezone
date_default_timezone_set('Europe/London');

try {
    $db = db();
    $user_id = (int)$_SESSION['user']['id'];
    
    // Get parameters
    $plan_id = isset($_GET['plan_id']) ? (int)$_GET['plan_id'] : 0;
    $donor_id = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0;
    
    if (!$plan_id || !$donor_id) {
        header('Location: index.php');
        exit;
    }
    
    // Comprehensive query to fetch all details
    $summary_query = "
        SELECT 
            -- Donor Details
            d.id as donor_id,
            d.name as donor_name,
            d.phone as donor_phone,
            d.city as donor_city,
            d.balance as donor_balance,
            d.payment_status as donor_payment_status,
            d.preferred_language as donor_language,
            
            -- Church Details
            c.name as church_name,
            
            -- Pledge Details
            p.id as pledge_id,
            p.amount as pledge_amount,
            p.created_at as pledge_date,
            p.status as pledge_status,
            
            -- Payment Plan Details
            pp.id as plan_id,
            pp.template_id,
            pp.total_amount as plan_total_amount,
            pp.monthly_amount as plan_monthly_amount,
            pp.total_months,
            pp.total_payments,
            pp.start_date as plan_start_date,
            pp.payment_day,
            pp.plan_frequency_unit,
            pp.plan_frequency_number,
            pp.payment_method,
            pp.next_payment_due,
            pp.status as plan_status,
            pp.created_at as plan_created_at,
            
            -- Call Session Details (try payment_plan_id first, then most recent session for this donor)
            cs.id as session_id,
            cs.agent_id,
            cs.call_started_at,
            cs.call_ended_at,
            cs.duration_seconds,
            cs.outcome as call_outcome,
            cs.conversation_stage,
            cs.notes as call_notes,
            
            -- Agent Details
            u.name as agent_name,
            
            -- Registrar Details
            COALESCE(
                (SELECT name FROM users WHERE id = d.registered_by_user_id LIMIT 1),
                (SELECT u2.name FROM pledges p2 JOIN users u2 ON p2.created_by_user_id = u2.id WHERE p2.donor_id = d.id ORDER BY p2.created_at DESC LIMIT 1),
                (SELECT u3.name FROM payments pay JOIN users u3 ON pay.received_by_user_id = u3.id WHERE pay.donor_id = d.id ORDER BY pay.created_at DESC LIMIT 1),
                'Unknown'
            ) as registrar_name
            
        FROM donor_payment_plans pp
        INNER JOIN donors d ON pp.donor_id = d.id
        LEFT JOIN pledges p ON pp.pledge_id = p.id
        LEFT JOIN churches c ON d.church_id = c.id
        LEFT JOIN call_center_sessions cs ON (
            cs.payment_plan_id = pp.id 
            OR (cs.donor_id = d.id AND cs.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR))
        )
        LEFT JOIN users u ON cs.agent_id = u.id
        WHERE pp.id = ? AND d.id = ?
        ORDER BY cs.created_at DESC, cs.id DESC
        LIMIT 1
    ";
    
    $stmt = $db->prepare($summary_query);
    $stmt->bind_param('ii', $plan_id, $donor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $summary = $result->fetch_object();
    $stmt->close();
    
    if (!$summary) {
        header('Location: index.php');
        exit;
    }
    
    // Format duration
    $duration_minutes = $summary->duration_seconds ? floor((int)$summary->duration_seconds / 60) : 0;
    $duration_seconds_remainder = $summary->duration_seconds ? (int)$summary->duration_seconds % 60 : 0;
    $duration_formatted = $duration_minutes > 0 
        ? sprintf('%d min %d sec', $duration_minutes, $duration_seconds_remainder)
        : sprintf('%d sec', $duration_seconds_remainder);
    
    // Format dates
    $call_started = $summary->call_started_at ? date('M j, Y g:i A', strtotime($summary->call_started_at)) : 'N/A';
    $call_ended = $summary->call_ended_at ? date('M j, Y g:i A', strtotime($summary->call_ended_at)) : 'N/A';
    $pledge_date = $summary->pledge_date ? date('M j, Y', strtotime($summary->pledge_date)) : 'N/A';
    $plan_start = $summary->plan_start_date ? date('M j, Y', strtotime($summary->plan_start_date)) : 'N/A';
    $plan_created = $summary->plan_created_at ? date('M j, Y g:i A', strtotime($summary->plan_created_at)) : 'N/A';
    $next_payment = $summary->next_payment_due ? date('M j, Y', strtotime($summary->next_payment_due)) : 'N/A';
    
    // Calculate last payment date
    $last_payment_date = 'N/A';
    if ($summary->plan_start_date && $summary->total_payments > 0) {
        $start = new DateTime($summary->plan_start_date);
        $frequency = $summary->plan_frequency_unit ?? 'month';
        $frequency_num = (int)($summary->plan_frequency_number ?? 1);
        
        // Logic: Start Date + (Total Payments - 1) * Frequency
        $intervals_to_add = ($summary->total_payments - 1) * $frequency_num;
        
        if ($intervals_to_add > 0) {
            // Ensure singular unit for modify string (e.g. "month" -> "months")
            $unit_str = $frequency . 's'; 
            $start->modify("+{$intervals_to_add} {$unit_str}");
        }
        
        $last_payment_date = $start->format('M j, Y');
    }
    
    // Format frequency
    $frequency_display = 'One-time';
    $frequency_sms = 'once';
    if ($summary->plan_frequency_unit) {
        $unit = $summary->plan_frequency_unit;
        $num = (int)($summary->plan_frequency_number ?? 1);
        
        if ($unit === 'day') {
            $frequency_display = $num === 1 ? 'Daily' : "Every {$num} days";
            $frequency_sms = $num === 1 ? 'daily' : "every {$num} days";
        } elseif ($unit === 'week') {
            $frequency_display = $num === 1 ? 'Weekly' : "Every {$num} weeks";
            $frequency_sms = $num === 1 ? 'weekly' : "every {$num} weeks";
        } elseif ($unit === 'month') {
            $frequency_display = $num === 1 ? 'Monthly' : "Every {$num} months";
            $frequency_sms = $num === 1 ? 'monthly' : "every {$num} months";
        } elseif ($unit === 'year') {
            $frequency_display = $num === 1 ? 'Annually' : "Every {$num} years";
            $frequency_sms = $num === 1 ? 'annually' : "every {$num} years";
        }
    }
    
    // Get representative name if cash payment
    $representative_name = null;
    if ($summary->payment_method === 'cash') {
        // Check if donor has a representative
        $rep_query = $db->query("
            SELECT u.name 
            FROM donors d 
            JOIN users u ON d.representative_id = u.id 
            WHERE d.id = {$donor_id}
        ");
        if ($rep_query && $rep_row = $rep_query->fetch_assoc()) {
            $representative_name = $rep_row['name'];
        }
    }
    
    // SMS System
    $sms_status = $_GET['sms'] ?? null;
    $sms_error = isset($_GET['sms_error']) ? urldecode($_GET['sms_error']) : null;
    $sent_channel = $_GET['channel'] ?? null; // 'whatsapp' or 'sms'
    $fallback_used = isset($_GET['fallback']) ? (int)$_GET['fallback'] : 0;
    
    // Check if SMS is available
    $sms_available = false;
    $sms_template = null;
    try {
        $sms_helper = new SMSHelper($db);
        $sms_available = $sms_helper->isReady();
        if ($sms_available) {
            $sms_template = $sms_helper->getTemplate('payment_plan_created');
        }
    } catch (Throwable $e) {
        // SMS not available
    }
    
    // Helper function to get first name
    function getFirstName(string $fullName): string {
        $parts = explode(' ', trim($fullName));
        return $parts[0] ?? $fullName;
    }
    
    // Handle SMS send request
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_sms'])) {
        verify_csrf();
        
        try {
            if ($sms_available && $sms_template) {
                $firstName = getFirstName($summary->donor_name);
                $amount = '£' . number_format((float)$summary->plan_monthly_amount, 0);
                $startDate = date('M j, Y', strtotime($summary->plan_start_date));
                $nextPaymentDate = $summary->next_payment_due ? date('M j, Y', strtotime($summary->next_payment_due)) : $startDate;
                
                // Build payment method text
                $paymentMethodText = ucfirst(str_replace('_', ' ', $summary->payment_method ?? 'bank transfer'));
                if ($summary->payment_method === 'cash' && $representative_name) {
                    $repFirstName = getFirstName($representative_name);
                    $paymentMethodText = "Cash (Rep: {$repFirstName})";
                }

                $variables = [
                    'name' => $firstName,
                    'amount' => $amount,
                    'frequency' => $frequency_sms,
                    'total_payments' => $summary->total_payments,
                    'start_date' => $startDate,
                    'payment_method' => $paymentMethodText,
                    'next_payment_due' => $nextPaymentDate,
                    'representative' => $representative_name ? getFirstName($representative_name) : '',
                    'portal_link' => 'https://bit.ly/4p0J1gf'
                ];

                // Successful outcome → WhatsApp first, fallback to SMS automatically
                $msg_helper = new MessagingHelper($db);

                // Check if WhatsApp is actually available BEFORE sending
                // so we can distinguish between "fallback" vs "WhatsApp wasn't an option"
                $whatsapp_was_available = $msg_helper->isWhatsAppAvailable();

                $result = $msg_helper->sendFromTemplate(
                    'payment_plan_created',
                    $donor_id,
                    $variables,
                    'whatsapp',
                    'call_center',
                    false, // queue
                    true   // forceImmediate
                );

                // MessagingHelper returns 'channel' only for WhatsApp success.
                // If it falls back to SMS, it returns the SMSHelper response (no channel).
                $sent_channel = $result['channel'] ?? 'sms';

                // Only flag as fallback if WhatsApp WAS available but we ended up using SMS
                // (i.e., WhatsApp was tried and failed). If WhatsApp wasn't available,
                // SMS was the determined channel, not a fallback.
                $fallback_used = ($whatsapp_was_available && $sent_channel === 'sms') ? 1 : 0;
                
                if ($result['success']) {
                    $sms_status = 'sent';
                } else {
                    $sms_status = 'failed';
                    $sms_error = $result['error'] ?? 'Unknown error';
                }
            }
        } catch (Throwable $e) {
            $sms_status = 'failed';
            $sms_error = $e->getMessage();
        }
        
        // Redirect to prevent form resubmission
        $redirect = "plan-success.php?plan_id=$plan_id&donor_id=$donor_id&sms=$sms_status";
        if ($sms_error) {
            $redirect .= '&sms_error=' . urlencode($sms_error);
        }
        if ($sent_channel) {
            $redirect .= '&channel=' . urlencode((string)$sent_channel);
        }
        if ($fallback_used) {
            $redirect .= '&fallback=1';
        }
        header("Location: $redirect");
        exit;
    }
    
} catch (Exception $e) {
    error_log("Plan Success Error: " . $e->getMessage());
    header('Location: index.php');
    exit;
}

$page_title = 'Payment Plan Summary';
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
        .summary-page {
            max-width: 1200px;
            margin: 0 auto;
            padding: 1rem;
            padding-top: 20px;
        }
        
        .success-header {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            color: white;
            border-radius: 12px;
            padding: 2rem;
            text-align: center;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        
        .success-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
        }
        
        .success-header h1 {
            font-size: 2rem;
            font-weight: 700;
            margin: 0;
        }
        
        .success-header p {
            font-size: 1.125rem;
            margin: 0.5rem 0 0 0;
            opacity: 0.95;
        }
        
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .summary-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .summary-card-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #f1f5f9;
        }
        
        .summary-card-header i {
            font-size: 1.5rem;
            color: #0a6286;
        }
        
        .summary-card-header h3 {
            font-size: 1.125rem;
            font-weight: 700;
            color: #0a6286;
            margin: 0;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 0.75rem 0;
            border-bottom: 1px solid #f8fafc;
        }
        
        .info-row:last-child {
            border-bottom: none;
        }
        
        .info-label {
            font-size: 0.875rem;
            font-weight: 600;
            color: #64748b;
            flex: 0 0 40%;
        }
        
        .info-value {
            font-size: 0.9375rem;
            font-weight: 500;
            color: #1e293b;
            text-align: right;
            flex: 1;
        }
        
        .info-value.highlight {
            color: #0a6286;
            font-weight: 700;
        }
        
        .info-value.amount {
            color: #dc2626;
            font-weight: 700;
            font-size: 1.125rem;
        }
        
        .info-value.success {
            color: #22c55e;
            font-weight: 700;
        }
        
        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            flex-wrap: wrap;
        }
        
        .action-buttons .btn {
            flex: 1;
            min-width: 150px;
        }
        
        .sms-option-card {
            background: linear-gradient(135deg, #f0f9ff, #e0f2fe);
            border: 2px solid #0ea5e9;
            border-radius: 12px;
            padding: 1.25rem;
        }
        
        .sms-option-header {
            color: #0284c7;
            font-size: 1rem;
            margin-bottom: 0.75rem;
        }
        
        .sms-preview {
            background: white;
            border: 1px solid #bae6fd;
            border-radius: 8px;
            padding: 1rem;
            font-size: 0.9375rem;
            color: #334155;
            line-height: 1.6;
        }
        
        .sms-meta {
            display: flex;
            gap: 1.5rem;
            margin-top: 0.75rem;
            font-size: 0.8125rem;
            color: #64748b;
            flex-wrap: wrap;
        }
        
        @media (max-width: 768px) {
            .summary-grid {
                grid-template-columns: 1fr;
            }
            
            .info-row {
                flex-direction: column;
                gap: 0.25rem;
            }
            
            .info-value {
                text-align: left;
            }
            
            .action-buttons .btn {
                flex: 1 1 100%;
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
            <div class="summary-page">
                <!-- Success Header -->
                <div class="success-header">
                    <div class="success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h1>Payment Plan Created Successfully!</h1>
                    <p>The payment plan has been set up and the call has been logged.</p>
                </div>
                
                <!-- Summary Grid -->
                <div class="summary-grid">
                    <!-- Donor Information -->
                    <div class="summary-card">
                        <div class="summary-card-header">
                            <i class="fas fa-user"></i>
                            <h3>Donor Information</h3>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Name</span>
                            <span class="info-value highlight"><?php echo htmlspecialchars($summary->donor_name); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Phone</span>
                            <span class="info-value">
                                <a href="tel:<?php echo htmlspecialchars($summary->donor_phone); ?>" style="color:inherit;text-decoration:none;">
                                    <?php echo htmlspecialchars($summary->donor_phone); ?>
                                </a>
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">City</span>
                            <span class="info-value"><?php echo htmlspecialchars($summary->donor_city ?? 'Not specified'); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Church</span>
                            <span class="info-value"><?php echo htmlspecialchars($summary->church_name ?? 'Not specified'); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Registered By</span>
                            <span class="info-value"><?php echo htmlspecialchars($summary->registrar_name); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Payment Status</span>
                            <span class="info-value success"><?php echo ucfirst($summary->donor_payment_status ?? 'pending'); ?></span>
                        </div>
                    </div>
                    
                    <!-- Pledge Details -->
                    <div class="summary-card">
                        <div class="summary-card-header">
                            <i class="fas fa-hand-holding-usd"></i>
                            <h3>Pledge Details</h3>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Pledge Amount</span>
                            <span class="info-value amount">£<?php echo number_format((float)$summary->pledge_amount, 2); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Pledge Date</span>
                            <span class="info-value"><?php echo $pledge_date; ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Pledge Status</span>
                            <span class="info-value success"><?php echo ucfirst($summary->pledge_status ?? 'pending'); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Outstanding Balance</span>
                            <span class="info-value amount">£<?php echo number_format((float)$summary->donor_balance, 2); ?></span>
                        </div>
                    </div>
                    
                    <!-- Payment Plan -->
                    <div class="summary-card">
                        <div class="summary-card-header">
                            <i class="fas fa-calendar-check"></i>
                            <h3>Payment Plan</h3>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Total Amount</span>
                            <span class="info-value amount">£<?php echo number_format((float)$summary->plan_total_amount, 2); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Installment Amount</span>
                            <span class="info-value amount">£<?php echo number_format((float)$summary->plan_monthly_amount, 2); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Frequency</span>
                            <span class="info-value"><?php echo $frequency_display; ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Total Payments</span>
                            <span class="info-value highlight"><?php echo $summary->total_payments; ?> payments</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Start Date</span>
                            <span class="info-value"><?php echo $plan_start; ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">First Payment</span>
                            <span class="info-value"><?php echo $plan_start; ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Last Payment</span>
                            <span class="info-value"><?php echo $last_payment_date; ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Next Payment Due</span>
                            <span class="info-value highlight"><?php echo $next_payment; ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Payment Method</span>
                            <span class="info-value"><?php echo ucfirst(str_replace('_', ' ', $summary->payment_method ?? 'Not specified')); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Plan Status</span>
                            <span class="info-value success"><?php echo ucfirst($summary->plan_status ?? 'active'); ?></span>
                        </div>
                    </div>
                    
                    <!-- Call Details -->
                    <div class="summary-card">
                        <div class="summary-card-header">
                            <i class="fas fa-phone-alt"></i>
                            <h3>Call Details</h3>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Agent</span>
                            <span class="info-value highlight"><?php echo htmlspecialchars($summary->agent_name ?? 'Unknown'); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Call Started</span>
                            <span class="info-value"><?php echo $call_started; ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Call Ended</span>
                            <span class="info-value"><?php echo $call_ended; ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Call Duration</span>
                            <span class="info-value highlight"><?php echo $duration_formatted; ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Outcome</span>
                            <span class="info-value success"><?php echo ucfirst(str_replace('_', ' ', $summary->call_outcome ?? 'completed')); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Plan Created</span>
                            <span class="info-value"><?php echo $plan_created; ?></span>
                        </div>
                        <?php if ($summary->call_notes): ?>
                        <div class="info-row">
                            <span class="info-label">Call Notes</span>
                            <span class="info-value" style="white-space: pre-wrap; text-align: left;"><?php echo htmlspecialchars($summary->call_notes); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- SMS Notification Section -->
                <?php if ($sms_status === 'sent'): ?>
                <div class="alert alert-success d-flex align-items-center mb-4">
                    <i class="fas fa-check-circle me-3 fa-lg"></i>
                    <div>
                        <strong>Confirmation Sent!</strong><br>
                        <small class="text-muted">
                            <?php
                                $channelLabel = $sent_channel === 'whatsapp' ? 'WhatsApp' : 'SMS';
                                echo htmlspecialchars("Sent via {$channelLabel}" . ($fallback_used ? ' — SMS fallback used' : '') . '.');
                            ?>
                        </small>
                    </div>
                </div>
                <?php elseif ($sms_status === 'failed'): ?>
                <div class="alert alert-warning d-flex align-items-center mb-4">
                    <i class="fas fa-exclamation-triangle me-3 fa-lg"></i>
                    <div>
                        <strong>Message Failed to Send</strong><br>
                        <small><?php echo htmlspecialchars($sms_error ?? 'Unknown error'); ?></small>
                    </div>
                </div>
                <?php elseif ($sms_available && $sms_template && !$sms_status): ?>
                <!-- SMS Option Card -->
                <?php
                $firstName = getFirstName($summary->donor_name);
                $amount = '£' . number_format((float)$summary->plan_monthly_amount, 0);
                $startDate = date('M j, Y', strtotime($summary->plan_start_date));
                $nextPaymentDate = $summary->next_payment_due ? date('M j, Y', strtotime($summary->next_payment_due)) : $startDate;
                
                // Build payment method text for preview
                $paymentMethodText = ucfirst(str_replace('_', ' ', $summary->payment_method ?? 'bank transfer'));
                if ($summary->payment_method === 'cash' && $representative_name) {
                    $repFirstName = getFirstName($representative_name);
                    $paymentMethodText = "Cash (Rep: {$repFirstName})";
                }
                
                // Use donor's preferred language for preview (fallback to English)
                $donorLang = $summary->donor_language ?? 'en';
                $langField = "message_{$donorLang}";
                $templateMessage = !empty($sms_template[$langField]) ? $sms_template[$langField] : $sms_template['message_en'];
                
                // Language display labels
                $langLabels = ['en' => '🇬🇧 English', 'am' => '🇪🇹 Amharic', 'ti' => '🇪🇷 Tigrinya'];
                $previewLangLabel = $langLabels[$donorLang] ?? $langLabels['en'];
                // Check if we're showing fallback
                $usingFallback = empty($sms_template[$langField]) && $donorLang !== 'en';
                
                $previewMessage = str_replace(
                    ['{name}', '{amount}', '{frequency}', '{total_payments}', '{start_date}', '{payment_method}', '{next_payment_due}', '{portal_link}'],
                    [$firstName, $amount, $frequency_sms, $summary->total_payments, $startDate, $paymentMethodText, $nextPaymentDate, 'https://bit.ly/4p0J1gf'],
                    $templateMessage
                );
                // Remove unused variables
                $previewMessage = preg_replace('/\{[a-z_]+\}/', '', $previewMessage);
                $previewMessage = trim($previewMessage);
                ?>
                <div class="sms-option-card mb-4">
                    <div class="sms-option-header">
                        <i class="fas fa-sms me-2"></i>
                        <strong>Send Confirmation Message?</strong>
                        <span class="badge bg-success ms-2">Recommended</span>
                        <span class="badge bg-info ms-1"><?php echo $previewLangLabel; ?></span>
                    </div>
                    <?php if ($usingFallback): ?>
                    <div class="alert alert-warning py-2 px-3 mb-2" style="font-size: 0.8125rem;">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        No <?php echo $langLabels[$donorLang] ?? $donorLang; ?> translation available — using English fallback.
                    </div>
                    <?php endif; ?>
                    <div class="sms-preview">
                        <?php echo htmlspecialchars($previewMessage); ?>
                    </div>
                    <div class="sms-meta">
                        <span><i class="fas fa-ruler me-1"></i><?php echo strlen($previewMessage); ?> chars</span>
                        <span><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($summary->donor_phone); ?></span>
                        <?php if (strlen($previewMessage) > 160): ?>
                        <span class="text-warning"><i class="fas fa-layer-group me-1"></i><?php echo ceil(strlen($previewMessage) / 153); ?> segments</span>
                        <?php endif; ?>
                    </div>
                    <form method="POST" class="mt-3">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="send_sms" value="1">
                        <button type="submit" class="btn btn-info w-100">
                            <i class="fas fa-paper-plane me-2"></i>Send Plan Confirmation (WhatsApp → SMS)
                        </button>
                    </form>
                </div>
                <?php elseif ($sms_available && !$sms_template && !$sms_status): ?>
                <!-- Template Missing Warning -->
                <div class="alert alert-warning mb-4">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>SMS Template Not Found!</strong><br>
                    <small>Please create a template with key <code>payment_plan_created</code> in 
                    <a href="../donor-management/sms/templates.php?action=new" target="_blank">SMS Templates</a></small>
                </div>
                <?php endif; ?>
                
                <!-- Action Buttons -->
                <div class="action-buttons">
                    <a href="index.php" class="btn btn-primary btn-lg">
                        <i class="fas fa-list me-2"></i>Back to Queue
                    </a>
                    <a href="../donor-management/view-donor.php?id=<?php echo $donor_id; ?>" class="btn btn-outline-primary btn-lg">
                        <i class="fas fa-user me-2"></i>View Donor Profile
                    </a>
                    <a href="call-history.php?donor_id=<?php echo $donor_id; ?>" class="btn btn-outline-secondary btn-lg">
                        <i class="fas fa-history me-2"></i>Call History
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
