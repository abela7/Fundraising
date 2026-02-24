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
    
    if (!$donor_id) {
        header('Location: ../donor-management/donors.php');
        exit;
    }
    
    // If no session exists, create one
    if ($session_id <= 0) {
        try {
            // Try to create session (queue_id might be nullable)
            $q_param = $queue_id > 0 ? $queue_id : null;
            $stmt = $db->prepare("INSERT INTO call_center_sessions (agent_id, donor_id, queue_id, call_started_at, conversation_stage) VALUES (?, ?, ?, NOW(), 'contact_made')");
            $stmt->bind_param('iii', $user_id, $donor_id, $q_param);
            $stmt->execute();
            $session_id = $db->insert_id;
            $stmt->close();
        } catch (Exception $e) {
            // If NULL fails, try 0
            error_log("Failed to create session with NULL queue_id: " . $e->getMessage());
            $q_param = 0;
            $stmt = $db->prepare("INSERT INTO call_center_sessions (agent_id, donor_id, queue_id, call_started_at, conversation_stage) VALUES (?, ?, ?, NOW(), 'contact_made')");
            $stmt->bind_param('iii', $user_id, $donor_id, $q_param);
            $stmt->execute();
            $session_id = $db->insert_id;
            $stmt->close();
        }
        
        // Redirect to include session_id
        header("Location: conversation.php?donor_id=$donor_id&session_id=$session_id&queue_id=$queue_id");
        exit;
    }
    
    // Update session status to 'connected' if not already
    if ($session_id > 0) {
        $update_session = "UPDATE call_center_sessions SET conversation_stage = 'contact_made' WHERE id = ? AND conversation_stage = 'attempt_failed'";
        $stmt = $db->prepare($update_session);
        if ($stmt) {
            $stmt->bind_param('i', $session_id);
            $stmt->execute();
            $stmt->close();
        }
    }
    
    // Get donor and pledge information (including fields for Step 2 and widget)
    $donor_query = "
        SELECT d.id, d.name, d.phone, d.balance, d.city, d.baptism_name, d.email, 
               d.preferred_language, d.church_id, d.preferred_payment_method,
               d.representative_id, d.total_pledged, d.total_paid, d.payment_status,
               d.source, d.created_at, d.admin_notes,
               COALESCE(p.amount, 0) as pledge_amount, 
               p.created_at as pledge_date,
               p.id as pledge_id,
               p.notes as pledge_notes,
               c.name as church_name,
                cr.name as representative_name,
                cr.phone as representative_phone,
               COALESCE(
                    (SELECT name FROM users WHERE id = d.registered_by_user_id LIMIT 1),
                    (SELECT u.name FROM pledges p2 JOIN users u ON p2.created_by_user_id = u.id WHERE p2.donor_id = d.id ORDER BY p2.created_at DESC LIMIT 1),
                    'Unknown'
                ) as registrar_name
        FROM donors d
        LEFT JOIN pledges p ON d.id = p.donor_id AND p.status = 'approved'
        LEFT JOIN churches c ON d.church_id = c.id
        LEFT JOIN church_representatives cr ON d.representative_id = cr.id
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
        header('Location: ../donor-management/donors.php');
        exit;
    }
    
    // --- Financial Totals (use live data to keep balances accurate) ---
    $donor_total_pledged = isset($donor->total_pledged) ? (float)$donor->total_pledged : 0.0;
    $donor_total_paid = isset($donor->total_paid) ? (float)$donor->total_paid : 0.0;
    $donor_balance = isset($donor->balance) ? (float)$donor->balance : 0.0;
    
    $tables_cache = [];
    $tableExists = function(string $table) use ($db, &$tables_cache): bool {
        if (!array_key_exists($table, $tables_cache)) {
            $safe = $db->real_escape_string($table);
            $result = $db->query("SHOW TABLES LIKE '{$safe}'");
            $tables_cache[$table] = $result && $result->num_rows > 0;
        }
        return $tables_cache[$table];
    };
    
    // Sum approved pledges
    $calculated_total_pledged = null;
    if ($tableExists('pledges')) {
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) AS total FROM pledges WHERE donor_id = ? AND status = 'approved'");
        if ($stmt) {
            $stmt->bind_param('i', $donor_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $calculated_total_pledged = isset($row['total']) ? (float)$row['total'] : 0.0;
            $stmt->close();
        }
    }
    
    // Sum approved payments (instant + pledge payments)
    $calculated_total_paid = 0.0;
    $has_payment_rows = false;
    
    if ($tableExists('payments') && !empty($donor->phone)) {
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) AS total FROM payments WHERE donor_phone = ? AND status = 'approved'");
        if ($stmt) {
            $stmt->bind_param('s', $donor->phone);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $amount = isset($row['total']) ? (float)$row['total'] : 0.0;
            if ($amount > 0) {
                $has_payment_rows = true;
            }
            $calculated_total_paid += $amount;
            $stmt->close();
        }
    }
    
    if ($tableExists('pledge_payments')) {
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) AS total FROM pledge_payments WHERE donor_id = ? AND status = 'confirmed'");
        if ($stmt) {
            $stmt->bind_param('i', $donor_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $amount = isset($row['total']) ? (float)$row['total'] : 0.0;
            if ($amount > 0) {
                $has_payment_rows = true;
            }
            $calculated_total_paid += $amount;
            $stmt->close();
        }
    }
    
    if ($calculated_total_pledged !== null && ($calculated_total_pledged > 0 || $donor_total_pledged <= 0)) {
        $donor_total_pledged = $calculated_total_pledged;
    }
    
    if ($has_payment_rows || $donor_total_paid <= 0) {
        $donor_total_paid = $calculated_total_paid;
    }
    
    $computed_balance = max($donor_total_pledged - $donor_total_paid, 0.0);
    if ($computed_balance > 0 || $donor_balance <= 0) {
        $donor_balance = $computed_balance;
    }
    
    // Sync computed values back to donor object for template usage
    $donor->total_pledged = $donor_total_pledged;
    $donor->total_paid = $donor_total_paid;
    $donor->balance = $donor_balance;
    
    if ((float)($donor->pledge_amount ?? 0) <= 0 && $donor_total_pledged > 0) {
        $donor->pledge_amount = $donor_total_pledged;
    }
    
    // Get churches list for dropdown
    $churches = [];
    $church_cities = [];
    $churches_query = $db->query("SELECT id, name, city FROM churches WHERE is_active = 1 ORDER BY city, name");
    if ($churches_query) {
        while ($row = $churches_query->fetch_assoc()) {
            $churches[] = $row;
            $city = trim((string)($row['city'] ?? ''));
            if ($city !== '') {
                $church_cities[strtolower($city)] = $city;
            }
        }
    }
    ksort($church_cities, SORT_NATURAL | SORT_FLAG_CASE);
    
    // Get representatives for donor's current church (will be updated from Step 2 if changed)
    // Use donor's existing church_id, or it will be set in Step 2
    $representatives = [];
    $current_church_id = $donor->church_id ?? null;
    
    if ($current_church_id) {
        $rep_query = $db->prepare("
            SELECT id, name, phone, role, is_primary 
            FROM church_representatives 
            WHERE church_id = ? AND is_active = 1 
            ORDER BY is_primary DESC, name ASC
        ");
        $rep_query->bind_param('i', $current_church_id);
        $rep_query->execute();
        $rep_result = $rep_query->get_result();
        while ($row = $rep_result->fetch_assoc()) {
            $representatives[] = $row;
        }
        $rep_query->close();
    }
    
    // Extract reference number from pledge notes (digits only)
    $reference_number = '';
    if (!empty($donor->pledge_notes)) {
        $reference_number = preg_replace('/\D+/', '', $donor->pledge_notes);
    }
    
    // Get payment plan templates
    $templates = [];
    $templates_check = $db->query("SHOW TABLES LIKE 'payment_plan_templates'");
    if ($templates_check && $templates_check->num_rows > 0) {
        $templates_query = "SELECT * FROM payment_plan_templates WHERE is_active = 1 ORDER BY duration_months ASC";
        $t_result = $db->query($templates_query);
        if ($t_result) {
            while ($row = $t_result->fetch_assoc()) {
                $templates[] = $row;
            }
        }
    }

    // If no templates, use defaults
    if (empty($templates)) {
        $templates = [
            ['id' => 'def_1', 'name' => 'One-Time Payment', 'duration_months' => 1],
            ['id' => 'def_3', 'name' => 'Quarterly (3 Months)', 'duration_months' => 3],
            ['id' => 'def_6', 'name' => '6 Months Plan', 'duration_months' => 6],
            ['id' => 'def_12', 'name' => '12 Months Plan', 'duration_months' => 12],
        ];
    }

    $has_one_time_template = false;
    foreach ($templates as $template) {
        if ((int)($template['duration_months'] ?? 0) === 1) {
            $has_one_time_template = true;
            break;
        }
    }
    
} catch (Exception $e) {
    error_log("Conversation Error: " . $e->getMessage());
    header('Location: index.php');
    exit;
}

$page_title = 'Live Call';
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
        .conversation-page {
            max-width: 1100px;
            margin: 0 auto;
            padding-top: 20px;
            padding-bottom: 120px; /* Space for fixed footer timer */
        }
        
        /* Hide FAB menu on conversation page */
        .fab-container {
            display: none !important;
        }
        
        /* Steps Wizard */
        .step-container {
            display: none;
            animation: fadeIn 0.3s ease-in-out;
        }
        
        .step-container.active {
            display: block;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .step-card {
            background: white;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        .step-header {
            background: #f8fafc;
            padding: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .step-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: #0a6286;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
        }
        
        .step-number {
            background: #0a6286;
            color: white;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            margin-right: 0.75rem;
        }
        
        .step-body {
            padding: 2rem;
        }
        
        /* Verification Styles */
        .verification-item {
            background: #f1f5f9;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: flex-start;
        }
        
        .form-check-input {
            width: 1.5em;
            height: 1.5em;
            margin-top: 0.2em;
            margin-right: 1em;
            cursor: pointer;
        }
        
        .verification-text {
            flex: 1;
        }
        
        .verification-question {
            font-weight: 600;
            color: #334155;
            margin-bottom: 0.25rem;
        }
        
        .verification-detail {
            color: #64748b;
            font-size: 0.9rem;
        }
        
        /* Step 2 Form Styles */
        .form-label {
            font-weight: 600;
            color: #334155;
            margin-bottom: 0.5rem;
            font-size: 0.9375rem;
        }
        
        .form-control, .form-select {
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 0.75rem 1rem;
            font-size: 0.9375rem;
            transition: all 0.2s;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #0a6286;
            box-shadow: 0 0 0 3px rgba(10, 98, 134, 0.1);
            outline: none;
        }
        
        .form-control::placeholder {
            color: #94a3b8;
        }
        
        .form-label small {
            display: block;
            font-weight: 400;
            color: #64748b;
            margin-top: 0.25rem;
            font-size: 0.8125rem;
        }
        
        .form-label small strong {
            color: #1e293b;
            font-weight: 600;
        }
        
        /* Field Status Badges */
        .form-label .badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            vertical-align: middle;
        }
        
        .form-control.border-success,
        .form-select.border-success {
            border-color: #22c55e !important;
            background-color: #f0fdf4;
        }
        
        .form-control.border-warning,
        .form-select.border-warning {
            border-color: #f59e0b !important;
            background-color: #fffbeb;
        }
        
        /* Choice Grid */
        .choice-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        .choice-card {
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .choice-card:hover {
            border-color: #0a6286;
            background: #f0f9ff;
        }
        
        .choice-card.selected {
            border-color: #0a6286;
            background: #e0f2fe;
            position: relative;
        }
        
        .choice-icon {
            font-size: 2rem;
            color: #0a6286;
            margin-bottom: 1rem;
        }
        
        .choice-label {
            font-weight: 700;
            font-size: 1.125rem;
        }
        
        /* Split Layout for Plan Selection */
        @media (min-width: 992px) {
            .step-split-layout {
                display: flex;
                gap: 1.5rem;
                align-items: flex-start;
            }
            .step-split-left {
                flex: 3;
            }
            .step-split-right {
                flex: 2;
                position: sticky;
                top: 100px;
            }
        }
        
        /* Plan Selection Styles */
        .plan-cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .plan-card {
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 1.25rem 1rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
            background: white;
        }
        
        .plan-card:hover {
            border-color: #3b82f6;
            transform: translateY(-2px);
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
        }
        
        .plan-card.selected {
            border-color: #0a6286;
            background: #f0f9ff;
            box-shadow: 0 4px 6px -1px rgba(10, 98, 134, 0.2);
        }
        
        .plan-name {
            font-weight: 700;
            font-size: 0.95rem;
            color: #0a6286;
            margin-bottom: 0.5rem;
        }
        
        .plan-duration {
            font-size: 0.85rem;
            color: #64748b;
            margin-bottom: 0.75rem;
        }
        
        .plan-check {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            border: 2px solid #e2e8f0;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            transition: all 0.2s;
        }
        
        .plan-card.selected .plan-check {
            background: #0a6286;
            border-color: #0a6286;
        }
        
        /* Plan Summary Box */
        .plan-summary-box {
            background: white;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            display: none; /* Hidden until plan selected */
        }
        
        .plan-summary-box.active {
            display: block;
            animation: fadeIn 0.3s;
        }
        
        .summary-header {
            background: #f8fafc;
            padding: 1rem 1.25rem;
            border-bottom: 1px solid #e2e8f0;
            font-weight: 700;
            color: #334155;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .summary-body {
            padding: 1.25rem;
        }
        
        .config-section {
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 1.25rem;
            margin-bottom: 1.25rem;
        }
        
        .preview-section {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1rem;
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }
        
        .summary-row.total {
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px solid #e2e8f0;
            font-weight: 700;
            font-size: 1.1rem;
            color: #0a6286;
        }
        
        .summary-row label {
            color: #64748b;
        }
        
        .summary-row span {
            color: #1e293b;
            font-weight: 600;
        }
        
        /* Step 2 Form Styles */
        .form-label {
            font-weight: 600;
            color: #334155;
            margin-bottom: 0.5rem;
            font-size: 0.9375rem;
        }
        
        .form-control, .form-select {
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 0.75rem 1rem;
            font-size: 0.9375rem;
            transition: all 0.2s;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #0a6286;
            box-shadow: 0 0 0 3px rgba(10, 98, 134, 0.1);
            outline: none;
        }
        
        .form-control::placeholder {
            color: #94a3b8;
        }
        
        .form-label small {
            display: block;
            font-weight: 400;
            color: #64748b;
            margin-top: 0.25rem;
            font-size: 0.8125rem;
        }
        
        /* Step 4 Confirmation */
        .confirmation-box {
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: 12px;
            padding: 2rem;
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .confirmation-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: #0a6286;
            margin-bottom: 1rem;
        }
        
        .confirmation-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
            text-align: left;
            max-width: 500px;
            margin: 0 auto;
        }
        
        /* Step 6 Payment Method Styles */
        .bank-details {
            background: white;
            padding: 1rem;
            border-radius: 8px;
            margin-top: 0.5rem;
        }
        
        .bank-details div {
            font-family: 'Courier New', monospace;
            font-size: 0.9375rem;
        }
        
        .reference-options {
            background: #fff3cd;
            padding: 1rem;
            border-radius: 8px;
            margin-top: 0.5rem;
        }
        
        .reference-options strong {
            color: #856404;
        }
        
        .conf-item label {
            display: block;
            font-size: 0.75rem;
            text-transform: uppercase;
            color: #64748b;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }
        
        .conf-item div {
            font-weight: 600;
            font-size: 1.1rem;
            color: #1e293b;
        }
        
        @media (max-width: 768px) {
            .choice-grid {
                grid-template-columns: 1fr;
            }
            
            .plan-cards-grid {
                grid-template-columns: 1fr 1fr;
            }
            
            .plan-summary-box {
                margin-top: 1.5rem;
            }
            
            .confirmation-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            
            /* Step 2 Mobile Styles */
            .step-body {
                padding: 1.5rem 1rem;
            }
            
            .step-body .row.g-3 {
                margin: 0;
            }
            
            .step-body .col-md-6 {
                padding: 0.5rem 0;
            }
            
            .form-control, .form-select {
                font-size: 16px; /* Prevents zoom on iOS */
            }
            
            .form-label {
                font-size: 0.875rem;
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
            <div class="conversation-page">
                <form id="conversationForm" method="POST" action="process-conversation.php">
                    <input type="hidden" name="session_id" value="<?php echo $session_id; ?>">
                    <input type="hidden" name="donor_id" value="<?php echo $donor_id; ?>">
                    <input type="hidden" name="queue_id" value="<?php echo $queue_id; ?>">
                    <input type="hidden" name="pledge_id" value="<?php echo $donor->pledge_id; ?>">
                    <input type="hidden" name="plan_template_id" id="selectedPlanId">
                    <input type="hidden" name="plan_duration" id="selectedDuration">
                    <input type="hidden" id="donorReferenceNumber" value="<?php echo htmlspecialchars((string)($reference_number ?? '')); ?>">
                    <input type="hidden" id="donorPhone" value="<?php echo htmlspecialchars((string)($donor->phone ?? '')); ?>">
                    <input type="hidden" name="conversation_stage_input" id="conversation_stage_input" value="connected">
                    <input type="hidden" name="donor_already_paid" id="donorAlreadyPaidInput" value="no">
                    <input type="hidden" name="paid_payment_method" id="paidPaymentMethodInput">
                    <input type="hidden" name="paid_payment_evidence" id="paidEvidenceInput" value="">
                    <input type="hidden" name="paid_whatsapp_sent" id="paidWhatsappSentInput" value="0">
                    
                    <!-- Step 1: Verification -->
                    <div class="step-container active" id="step1">
                        <div class="step-card">
                            <div class="step-header">
                                <div class="step-title">
                                    <span class="step-number">1</span>
                                    Verify Pledge Details
                                </div>
                                <p class="text-muted mb-0">Ask the donor to confirm their pledge information.</p>
                            </div>
                            <div class="step-body">
                                <div class="verification-item">
                                    <input class="form-check-input" type="checkbox" id="verifyAmount">
                                    <div class="verification-text">
                                        <div class="verification-question">
                                                "Did you pledge £<?php echo number_format((float)$donor->total_pledged, 2); ?>?"
                                        </div>
                                        <div class="verification-detail">
                                                Remaining Balance: £<?php echo number_format((float)$donor->balance, 2); ?><br>
                                                Latest Approved Pledge: £<?php echo number_format((float)$donor->pledge_amount, 2); ?>
                                                <?php if ($donor->pledge_date): ?>
                                                    on <?php echo date('M j, Y', strtotime($donor->pledge_date)); ?>
                                                <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <?php if ($donor->church_name || $donor->city): ?>
                                <div class="verification-item">
                                    <input class="form-check-input" type="checkbox" id="verifyLocation">
                                    <div class="verification-text">
                                        <div class="verification-question">
                                            "Is your church <?php echo htmlspecialchars($donor->church_name ?? 'Unknown'); ?> in <?php echo htmlspecialchars($donor->city ?? 'Unknown'); ?>?"
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <div class="mt-4 d-flex justify-content-between">
                                    <button type="button" class="btn btn-outline-secondary" onclick="window.location.href='../donor-management/donors.php'">
                                        <i class="fas fa-times me-2"></i>Cancel Call
                                    </button>
                                    <button type="button" class="btn btn-primary btn-lg" id="btnStep1Next" disabled onclick="goToStep(2)">
                                        Next Step <i class="fas fa-arrow-right ms-2"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Step 2: Payment Status -->
                    <div class="step-container" id="step2">
                        <div class="step-card">
                            <div class="step-header">
                                <div class="step-title">
                                    <span class="step-number">2</span>
                                    Payment Status
                                </div>
                                <p class="text-muted mb-0">Ask the donor whether they have already paid the full pledge amount.</p>
                            </div>
                            <div class="step-body">
                                <div class="choice-grid">
                                    <div class="choice-card" onclick="selectPaymentStatus('yes', this)">
                                        <div class="choice-icon"><i class="fas fa-check-circle text-success"></i></div>
                                        <div class="choice-label">Yes, already paid</div>
                                        <p class="text-muted mt-2 mb-0">Collect donor information first, then confirm payment method.</p>
                                    </div>
                                    
                                    <div class="choice-card" onclick="selectPaymentStatus('no', this)">
                                        <div class="choice-icon"><i class="fas fa-hourglass-half text-warning"></i></div>
                                        <div class="choice-label">No, have made a pledge but not paid</div>
                                        <p class="text-muted mt-2 mb-0">Continue with the payment plan flow</p>
                                    </div>
                                </div>
                                
                                <div class="mt-4 d-flex justify-content-between">
                                    <button type="button" class="btn btn-outline-secondary" onclick="goToStep(1)">
                                        <i class="fas fa-arrow-left me-2"></i>Back
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Step 3: Payment Verification (Already Paid) -->
                    <div class="step-container" id="step3">
                        <div class="step-card">
                            <div class="step-header">
                                <div class="step-title">
                                    <span class="step-number">3</span>
                                    Already Paid Confirmation
                                </div>
                                <p class="text-muted mb-0">Ask if they can remember the payment method and request proof by screenshot/reference/payment day.</p>
                            </div>
                            <div class="step-body">
                                <div class="mb-4">
                                    <label class="form-label">How did they pay?</label>
                                    <div class="choice-grid">
                                    <div class="choice-card" onclick="selectPaidPaymentMethod('bank_transfer', this)">
                                            <div class="choice-icon"><i class="fas fa-university text-primary"></i></div>
                                            <div class="choice-label">Bank Transfer</div>
                                        </div>
                                        
                                    <div class="choice-card" onclick="selectPaidPaymentMethod('card', this)">
                                            <div class="choice-icon"><i class="fas fa-credit-card text-info"></i></div>
                                            <div class="choice-label">Card / Direct Debit</div>
                                        </div>
                                        
                                    <div class="choice-card" onclick="selectPaidPaymentMethod('cash', this)">
                                            <div class="choice-icon"><i class="fas fa-money-bill-wave text-success"></i></div>
                                            <div class="choice-label">Cash</div>
                                        </div>
                                        
                                    <div class="choice-card" onclick="selectPaidPaymentMethod('other', this)">
                                            <div class="choice-icon"><i class="fas fa-ellipsis-h text-secondary"></i></div>
                                            <div class="choice-label">Other / Not sure</div>
                                        </div>
                                    </div>
                                    <!-- selected payment method for already paid flow is stored in the top-level paidPaymentMethodInput field -->
                                </div>
                                
                                <div class="alert alert-info mb-3">
                                    <p class="mb-2"><strong>Request proof from donor:</strong> Ask them to send any screenshot, transfer reference, or payment day so we can confirm their payment.</p>
                                    <div class="form-check mb-3">
                                        <input type="checkbox" class="form-check-input" id="paidWhatsappDifferentNumber" onchange="togglePaidWhatsappNumberInput(this)">
                                        <label class="form-check-label" for="paidWhatsappDifferentNumber">Use a different WhatsApp number</label>
                                    </div>
                                    <div id="paidWhatsappNumberWrap" style="display:none;" class="mb-3">
                                        <label for="paidWhatsappNumberInput" class="form-label">WhatsApp number to contact donor</label>
                                        <input
                                            type="text"
                                            class="form-control"
                                            id="paidWhatsappNumberInput"
                                            placeholder="+44 7xx xxx xxxx"
                                            oninput="syncPaidWhatsappNumber()">
                                        <small class="text-muted">Enter the number currently used by the donor on WhatsApp.</small>
                                    </div>
                                    <button type="button" class="btn btn-success btn-sm" id="sendPaidWhatsAppBtn" onclick="sendPaidWhatsAppRequest()">
                                        <i class="fab fa-whatsapp me-1"></i>Send WhatsApp Request to Donor
                                    </button>
                                    <div id="paidWhatsappStatus" class="small text-muted mt-2">No request sent yet.</div>
                                </div>
                                <div id="paidSkipNotice" class="alert alert-warning py-2 mb-3" style="font-size: 0.8125rem;">
                                    <i class="fas fa-info-circle me-1"></i>
                                    WhatsApp proof request is <strong>optional</strong>. You can complete the call without sending it.
                                </div>
                                
                                <div class="mb-3">
                                    <label for="paidEvidenceText" class="form-label">Evidence details (optional)</label>
                                    <textarea id="paidEvidenceText" class="form-control" rows="3" placeholder="Any reference, transaction ID, screenshot note, or payment day details." oninput="updatePaidEvidence(this.value)"></textarea>
                                    <small class="text-muted">If they already shared proof details, you can paste them here.</small>
                                </div>
                                
                                <div class="mt-4 d-flex justify-content-between">
                                    <button type="button" class="btn btn-outline-secondary" onclick="goToStep(4)">
                                        <i class="fas fa-arrow-left me-2"></i>Back
                                    </button>
                                    <button type="button" class="btn btn-success btn-lg" id="btnStep3Next" onclick="submitForm()" disabled>
                                        Complete Call <i class="fas fa-check-double ms-2"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Step 4: Collect Donor Information -->
                    <div class="step-container" id="step4">
                        <div class="step-card">
                            <div class="step-header">
                                <div class="step-title">
                                    <span class="step-number">4</span>
                                    Collect Donor Information
                                </div>
                                <p class="text-muted mb-0">
                                    <?php 
                                    $hasExistingData = !empty($donor->baptism_name) || !empty($donor->city) || !empty($donor->email) || !empty($donor->church_id);
                                    if ($hasExistingData): 
                                    ?>
                                        Review and update donor information if needed.
                                    <?php else: ?>
                                        Please collect the following information from the donor.
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="step-body">
                                <?php if ($hasExistingData): ?>
                                <div class="alert alert-info mb-4">
                                    <i class="fas fa-info-circle me-2"></i>
                                    <strong>Existing Information Found:</strong> The donor already has some information in the system. Please verify and update if anything has changed.
                                </div>
                                <?php endif; ?>
                                
                                <div class="row g-3">
                                    <!-- Baptism Name -->
                                    <div class="col-md-6">
                                        <label for="baptism_name" class="form-label">
                                            <i class="fas fa-water me-2 text-primary"></i>Baptism Name
                                            <?php if (!empty($donor->baptism_name)): ?>
                                                <span class="badge bg-success ms-2" id="badge-baptism-name">
                                                    <i class="fas fa-check me-1"></i>Existing
                                                </span>
                                            <?php endif; ?>
                                        </label>
                                        <input type="text" 
                                               class="form-control <?php echo !empty($donor->baptism_name) ? 'border-success' : ''; ?>" 
                                               id="baptism_name" 
                                               name="baptism_name" 
                                               placeholder="Enter baptism name"
                                               value="<?php echo htmlspecialchars($donor->baptism_name ?? ''); ?>"
                                               onchange="updateFieldBadge('baptism_name', this.value)">
                                        <small class="text-muted">
                                            <?php if (!empty($donor->baptism_name)): ?>
                                                Current: <strong><?php echo htmlspecialchars($donor->baptism_name); ?></strong> - Update if changed
                                            <?php else: ?>
                                                The donor's baptism name
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                    
                                    <!-- City -->
                                    <div class="col-md-6">
                                        <label for="city" class="form-label">
                                            <i class="fas fa-map-marker-alt me-2 text-primary"></i>City
                                            <?php if (!empty($donor->city)): ?>
                                                <span class="badge bg-success ms-2" id="badge-city">
                                                    <i class="fas fa-check me-1"></i>Existing
                                                </span>
                                            <?php endif; ?>
                                        </label>
                                        <input type="text" 
                                               class="form-control <?php echo !empty($donor->city) ? 'border-success' : ''; ?>" 
                                               id="city" 
                                               name="city" 
                                               placeholder="Enter city"
                                               value="<?php echo htmlspecialchars($donor->city ?? ''); ?>"
                                               onchange="updateFieldBadge('city', this.value)">
                                        <small class="text-muted">
                                            <?php if (!empty($donor->city)): ?>
                                                Current: <strong><?php echo htmlspecialchars($donor->city); ?></strong> - Update if changed
                                            <?php else: ?>
                                                Where the donor lives
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                    
                                    <!-- Church -->
                                    <div class="col-12">
                                        <div class="row g-2">
                                            <div class="col-md-5">
                                                <label for="church_city_filter" class="form-label small text-muted">Filter by City</label>
                                                <select class="form-select" id="church_city_filter" onchange="filterChurchOptions()">
                                                    <option value="">All Cities</option>
                                                    <?php foreach ($church_cities as $cityValue => $cityName): ?>
                                                        <option value="<?php echo htmlspecialchars(strtolower((string)$cityValue)); ?>">
                                                            <?php echo htmlspecialchars($cityName); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-7">
                                                <label for="church_search" class="form-label small text-muted">Search Church</label>
                                                <div class="input-group">
                                                    <input type="text"
                                                           class="form-control"
                                                           id="church_search"
                                                           placeholder="Type church name"
                                                           oninput="filterChurchOptions()">
                                                    <button type="button" class="btn btn-outline-secondary" onclick="clearChurchFilters()">
                                                        Clear
                                                    </button>
                                                </div>
                                            </div>
                                            <div class="col-12">
                                                <div id="churchFilterCount" class="form-text text-muted">Type or filter to narrow churches</div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <label for="church_id" class="form-label">
                                            <i class="fas fa-church me-2 text-primary"></i>Which Church Attending Regularly
                                            <?php if (!empty($donor->church_id)): ?>
                                                <span class="badge bg-success ms-2" id="badge-church">
                                                    <i class="fas fa-check me-1"></i>Existing
                                                </span>
                                            <?php endif; ?>
                                        </label>
                                        <select class="form-select <?php echo !empty($donor->church_id) ? 'border-success' : ''; ?>" 
                                                id="church_id" 
                                                name="church_id"
                                                onchange="updateFieldBadge('church', this.value)">
                                            <option value="">-- Select Church --</option>
                                            <?php foreach ($churches as $church): ?>
                                                <?php
                                                    $city = strtolower(trim((string)($church['city'] ?? '')));
                                                    if ($city === '') {
                                                        $city = '__unknown__';
                                                    }
                                                    $searchText = strtolower(trim((string)$church['name'] . ' ' . ($church['city'] ?? '')));
                                                ?>
                                                <option value="<?php echo $church['id']; ?>" 
                                                        class="church-option"
                                                        data-city="<?php echo htmlspecialchars($city); ?>"
                                                        data-search="<?php echo htmlspecialchars($searchText); ?>"
                                                        <?php echo (isset($donor->church_id) && $donor->church_id == $church['id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($church['name']); ?> 
                                                    <?php if ($church['city']): ?>
                                                        (<?php echo htmlspecialchars($church['city']); ?>)
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="text-muted">
                                            <?php if (!empty($donor->church_id) && !empty($donor->church_name)): ?>
                                                Current: <strong><?php echo htmlspecialchars($donor->church_name); ?></strong> - Update if changed
                                            <?php else: ?>
                                                Church the donor attends regularly
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                    
                                    <!-- Email -->
                                    <div class="col-md-6">
                                        <label for="email" class="form-label">
                                            <i class="fas fa-envelope me-2 text-primary"></i>Email Address
                                            <?php if (!empty($donor->email)): ?>
                                                <span class="badge bg-success ms-2" id="badge-email">
                                                    <i class="fas fa-check me-1"></i>Existing
                                                </span>
                                            <?php endif; ?>
                                        </label>
                                        <input type="email" 
                                               class="form-control <?php echo !empty($donor->email) ? 'border-success' : ''; ?>" 
                                               id="email" 
                                               name="email" 
                                               placeholder="donor@example.com"
                                               value="<?php echo htmlspecialchars($donor->email ?? ''); ?>"
                                               onchange="updateFieldBadge('email', this.value)">
                                        <small class="text-muted">
                                            <?php if (!empty($donor->email)): ?>
                                                Current: <strong><?php echo htmlspecialchars($donor->email); ?></strong> - Update if changed
                                            <?php else: ?>
                                                Email for communication
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                    
                                    <!-- Preferred Language -->
                                    <div class="col-md-6">
                                        <label for="preferred_language" class="form-label">
                                            <i class="fas fa-language me-2 text-primary"></i>Preferred Language
                                            <?php if (!empty($donor->preferred_language) && $donor->preferred_language !== 'en'): ?>
                                                <span class="badge bg-success ms-2" id="badge-language">
                                                    <i class="fas fa-check me-1"></i>Existing
                                                </span>
                                            <?php endif; ?>
                                        </label>
                                        <select class="form-select <?php echo (!empty($donor->preferred_language) && $donor->preferred_language !== 'en') ? 'border-success' : ''; ?>" 
                                                id="preferred_language" 
                                                name="preferred_language"
                                                onchange="updateFieldBadge('language', this.value)">
                                            <option value="en" <?php echo (!isset($donor->preferred_language) || $donor->preferred_language === 'en') ? 'selected' : ''; ?>>
                                                English
                                            </option>
                                            <option value="am" <?php echo (isset($donor->preferred_language) && $donor->preferred_language === 'am') ? 'selected' : ''; ?>>
                                                Amharic (አማርኛ)
                                            </option>
                                            <option value="ti" <?php echo (isset($donor->preferred_language) && $donor->preferred_language === 'ti') ? 'selected' : ''; ?>>
                                                Tigrinya (ትግርኛ)
                                            </option>
                                        </select>
                                        <small class="text-muted">
                                            <?php 
                                            $langLabels = ['en' => 'English', 'am' => 'Amharic', 'ti' => 'Tigrinya'];
                                            $currentLang = $donor->preferred_language ?? 'en';
                                            if ($currentLang !== 'en'): 
                                            ?>
                                                Current: <strong><?php echo $langLabels[$currentLang]; ?></strong> - Update if changed
                                            <?php else: ?>
                                                Preferred language for communication
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                </div>
                                
                                <div class="mt-4 d-flex justify-content-between">
                                    <button type="button" class="btn btn-outline-secondary" onclick="goToDonorInfoBack()">
                                        <i class="fas fa-arrow-left me-2"></i>Back
                                    </button>
                                    <button type="button" class="btn btn-primary btn-lg" id="btnStep4Next" onclick="proceedFromDonorInfo()">
                                        Next Step <i class="fas fa-arrow-right ms-2"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Step 5: Payment Readiness -->
                    <div class="step-container" id="step5">
                        <div class="step-card">
                            <div class="step-header">
                                <div class="step-title">
                                    <span class="step-number">5</span>
                                    Payment Readiness
                                </div>
                                <p class="text-muted mb-0">Is the donor ready to start paying today?</p>
                            </div>
                            <div class="step-body">
                                <div class="choice-grid">
                                    <div class="choice-card" onclick="selectReadiness('yes')">
                                        <div class="choice-icon"><i class="fas fa-check-circle text-success"></i></div>
                                        <div class="choice-label">Yes, Ready to Pay</div>
                                        <p class="text-muted mt-2 mb-0">Proceed to payment plans</p>
                                    </div>
                                    
                                    <div class="choice-card" onclick="selectReadiness('no')">
                                        <div class="choice-icon"><i class="fas fa-clock text-warning"></i></div>
                                        <div class="choice-label">No, Not Ready</div>
                                        <p class="text-muted mt-2 mb-0">Schedule for later or discuss reasons</p>
                                    </div>
                                    
                                    <div class="choice-card" onclick="selectReadiness('refused')">
                                        <div class="choice-icon"><i class="fas fa-times-circle text-danger"></i></div>
                                        <div class="choice-label">Refused / Cannot Pay</div>
                                        <p class="text-muted mt-2 mb-0">Close case without scheduling</p>
                                    </div>
                                </div>
                                <input type="hidden" name="ready_to_pay" id="readyToPayInput">
                                
                                <div class="mt-4 d-flex justify-content-between">
                                    <button type="button" class="btn btn-outline-secondary" onclick="goToStep(4)">
                                        <i class="fas fa-arrow-left me-2"></i>Back
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Step 6: Payment Plan -->
                    <div class="step-container" id="step6">
                        <div class="step-card">
                            <div class="step-header">
                                <div class="step-title">
                                    <span class="step-number">6</span>
                                    Select Payment Plan
                                </div>
                                <p class="text-muted mb-0">How would they like to clear the balance?</p>
                            </div>
                            <div class="step-body">
                                <div class="step-split-layout">
                                    <div class="step-split-left">
                                        <h6 class="mb-3 text-muted small fw-bold text-uppercase">Available Plans</h6>
                                        <div class="plan-cards-grid">
                                            <div class="plan-card" onclick="selectPlan('def_0', 1)">
                                                <div class="plan-name">Pay Full Balance Now</div>
                                                <div class="plan-duration">One-Time Payment</div>
                                                <div class="plan-check">
                                                    <i class="fas fa-bolt"></i>
                                                </div>
                                            </div>

                                            <?php foreach ($templates as $template): ?>
                                                <?php if ((int)($template['duration_months'] ?? 0) === 1) { continue; } ?>
                                                <div class="plan-card" onclick="selectPlan('<?php echo $template['id']; ?>', <?php echo $template['duration_months']; ?>)">
                                                    <div class="plan-name"><?php echo htmlspecialchars($template['name']); ?></div>
                                                    <div class="plan-duration">
                                                        <?php echo $template['duration_months'] > 1 ? $template['duration_months'] . ' Months' : 'One-time'; ?>
                                                    </div>
                                                    <div class="plan-check">
                                                        <i class="fas fa-check"></i>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        
                                        <!-- Back Button for Desktop (Left Column) -->
                                        <button type="button" class="btn btn-outline-secondary d-none d-lg-inline-block" onclick="goToStep(5)">
                                            <i class="fas fa-arrow-left me-2"></i>Back
                                        </button>
                                    </div>
                                    
                                    <div class="step-split-right">
                                        <!-- Summary & Preview Box -->
                                        <div id="planSummaryBox" class="plan-summary-box">
                                            <div class="summary-header">
                                                <span><i class="fas fa-file-invoice-dollar me-2"></i>Plan Preview</span>
                                            </div>
                                            <div class="summary-body">
                                                <!-- Config Section -->
                                                <div class="config-section">
                                                    <div class="mb-3">
                                                        <label class="form-label small fw-bold text-muted">
                                                            <i class="fas fa-calendar-day me-1 text-primary"></i>First Payment Date
                                                        </label>
                                                        <input type="date" class="form-control form-control-sm" name="start_date" id="startDate" 
                                                               value="<?php echo date('Y-m-d'); ?>" min="<?php echo date('Y-m-d'); ?>" onchange="calculatePreview()">
                                                        <small class="text-muted">When should the first installment be due?</small>
                                                    </div>
                                                    
                                                    <!-- Custom Plan Options (Visible only for custom) -->
                                                    <div id="customPlanOptions" style="display: none;">
                                                        <div class="mb-3">
                                                            <label class="form-label small fw-bold text-muted">
                                                                <i class="fas fa-redo me-1 text-info"></i>Repeat Every
                                                            </label>
                                                            <div class="input-group input-group-sm">
                                                                <input type="number" class="form-control" name="custom_frequency_number" id="customFreqNumber" value="1" min="1" onchange="calculatePreview()">
                                                                <select class="form-select" name="custom_frequency_unit" id="customFreqUnit" onchange="calculatePreview()">
                                                                    <option value="day">Day(s)</option>
                                                                    <option value="week">Week(s)</option>
                                                                    <option value="month" selected>Month(s)</option>
                                                                    <option value="year">Year(s)</option>
                                                            </select>
                                                            </div>
                                                        </div>
                                                        
                                                        <div class="mb-3">
                                                            <label class="form-label small fw-bold text-muted">
                                                                <i class="fas fa-hashtag me-1 text-success"></i>Number of Payments
                                                            </label>
                                                            <div class="input-group input-group-sm">
                                                                <button type="button" class="btn btn-outline-secondary" onclick="adjustPayments(-1)">-</button>
                                                                <input type="number" class="form-control text-center" name="custom_payments" id="customPayments" 
                                                                       value="12" min="1" max="120" onchange="calculatePreview()">
                                                                <button type="button" class="btn btn-outline-secondary" onclick="adjustPayments(1)">+</button>
                                                            </div>
                                                        </div>
                                                        
                                                        <div class="mb-3">
                                                            <label class="form-label small fw-bold text-muted">
                                                                <i class="fas fa-calendar-alt me-1 text-warning"></i>Recurring Payment Day
                                                            </label>
                                                            <input type="number" class="form-control form-control-sm" name="custom_payment_day" id="customPaymentDay" 
                                                                   value="1" min="1" max="28" placeholder="Day of Month (1-28)" onchange="calculatePreview()">
                                                            <small class="text-muted">Day of month for subsequent payments</small>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <!-- Preview Section -->
                                                <div class="preview-section">
                                                    <div class="summary-row" style="background: #e0f2fe; margin: -1rem -1rem 0.75rem -1rem; padding: 0.75rem 1rem; border-radius: 8px 8px 0 0;">
                                                        <label><i class="fas fa-calendar-check me-1 text-primary"></i><strong>1st Installment</strong></label>
                                                        <span class="text-primary fw-bold" id="previewFirstDate">-</span>
                                                    </div>
                                                    <div class="summary-row">
                                                        <label>Total Installments</label>
                                                        <span id="previewCount">-</span>
                                                    </div>
                                                    <div class="summary-row">
                                                        <label>Last Payment</label>
                                                        <span id="previewLastDate">-</span>
                                                    </div>
                                                    <div class="summary-row total">
                                                        <label id="previewAmountLabel">Monthly</label>
                                                        <span class="text-success" id="previewMonthly">-</span>
                                                    </div>
                                                    <div class="summary-row" style="margin-bottom: 0;">
                                                        <label>Total Pledge</label>
                                                        <span>£<?php echo number_format((float)$donor->balance, 2); ?></span>
                                                    </div>
                                                </div>
                                                
                                                <div class="mt-3">
                                                    <button type="button" class="btn btn-primary w-100" id="btnReview" disabled onclick="goToStep(7)">
                                                        Review Plan <i class="fas fa-arrow-right ms-2"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Placeholder when no plan selected -->
                                        <div id="planPlaceholder" class="text-center py-5 text-muted">
                                            <i class="fas fa-arrow-left mb-2" style="font-size: 1.5rem;"></i>
                                            <p>Select a plan on the left to see details</p>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Mobile Back Button -->
                                <div class="mt-4 d-lg-none">
                                    <button type="button" class="btn btn-outline-secondary" onclick="goToStep(5)">
                                        <i class="fas fa-arrow-left me-2"></i>Back
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Step 7: Review & Confirm -->
                    <div class="step-container" id="step7">
                        <div class="step-card">
                            <div class="step-header">
                                <div class="step-title">
                                    <span class="step-number">7</span>
                                    Confirm & Save
                                </div>
                                <p class="text-muted mb-0">Review the plan details with the donor before saving.</p>
                            </div>
                            <div class="step-body">
                                <div class="confirmation-box">
                                    <div class="confirmation-title">
                                        <i class="fas fa-check-circle text-success me-2"></i>
                                        Payment Plan Summary
                                    </div>
                                    
                                    <!-- First Payment Highlight -->
                                    <div class="text-center mb-3 p-3" style="background: linear-gradient(135deg, #0a6286 0%, #0d7fa6 100%); border-radius: 10px; color: white;">
                                        <small class="d-block mb-1 opacity-75">FIRST PAYMENT DUE</small>
                                        <div class="fs-4 fw-bold" id="confStart">-</div>
                                    </div>
                                    
                                    <div class="confirmation-grid">
                                        <div class="conf-item">
                                            <label>Total Amount</label>
                                            <div id="confTotal">£<?php echo number_format((float)$donor->balance, 2); ?></div>
                                        </div>
                                        <div class="conf-item">
                                            <label>Frequency</label>
                                            <div id="confFrequency">-</div>
                                        </div>
                                        <div class="conf-item">
                                            <label>Installment Amount</label>
                                            <div class="text-success" id="confInstallment">-</div>
                                        </div>
                                        <div class="conf-item">
                                            <label>Total Payments</label>
                                            <div id="confCount">-</div>
                                        </div>
                                        <div class="conf-item">
                                            <label>Last Payment</label>
                                            <div id="confEnd">-</div>
                                        </div>
                                        <div class="conf-item">
                                            <label>Payment Day</label>
                                            <div id="confPaymentDay">-</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="alert alert-warning d-flex align-items-center">
                                    <i class="fas fa-exclamation-triangle me-3 fs-4"></i>
                                    <div>
                                        <strong>Confirm with Donor:</strong>
                                        "You agree to pay <span id="confTextAmount"></span> <span id="confTextFreq"></span> starting on <span id="confTextDate"></span>?"
                                    </div>
                                </div>
                                
                                <div class="mt-4 d-flex justify-content-between">
                                    <button type="button" class="btn btn-outline-secondary" onclick="goToStep(6)">
                                        <i class="fas fa-arrow-left me-2"></i>Edit Plan
                                    </button>
                                    <button type="button" class="btn btn-primary btn-lg" onclick="goToStep(8)">
                                        Next: Payment Method <i class="fas fa-arrow-right ms-2"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Step 8: Payment Method -->
                    <div class="step-container" id="step8">
                        <div class="step-card">
                            <div class="step-header">
                                <div class="step-title">
                                    <span class="step-number">8</span>
                                    Payment Method
                                </div>
                                <p class="text-muted mb-0">How would the donor like to make payments?</p>
                            </div>
                            <div class="step-body">
                                <input type="hidden" name="payment_method" id="paymentMethodInput">
                                
                                <!-- Payment Method Selection -->
                                <div class="choice-grid mb-4">
                                    <div class="choice-card" onclick="selectPaymentMethod('bank_transfer', this)">
                                        <div class="choice-icon"><i class="fas fa-university text-primary"></i></div>
                                        <div class="choice-label">Bank Transfer</div>
                                        <p class="text-muted mt-2 mb-0">Transfer the amount directly</p>
                                    </div>
                                    
                                    <div class="choice-card" onclick="selectPaymentMethod('card', this)">
                                        <div class="choice-icon"><i class="fas fa-credit-card text-info"></i></div>
                                        <div class="choice-label">Direct Debit / Card</div>
                                        <p class="text-muted mt-2 mb-0">Set up automatic payments</p>
                                    </div>
                                    
                                    <div class="choice-card" onclick="selectPaymentMethod('cash', this)">
                                        <div class="choice-icon"><i class="fas fa-money-bill-wave text-success"></i></div>
                                        <div class="choice-label">Cash</div>
                                        <p class="text-muted mt-2 mb-0">Pay cash to representative</p>
                                    </div>
                                </div>
                                
                                <!-- Bank Transfer / Card Details (shown when selected) -->
                                    <div id="bankDetailsSection" style="display: none;">
                                        <div class="d-flex flex-wrap gap-2 mb-3">
                                            <button type="button" class="btn btn-success btn-sm" id="sendBankWhatsAppBtn" onclick="sendBankDetails('whatsapp')">
                                                <i class="fab fa-whatsapp me-1"></i>Send bank details to WhatsApp
                                            </button>
                                            <button type="button" class="btn btn-primary btn-sm" id="sendBankSmsBtn" onclick="sendBankDetails('sms')">
                                                <i class="fas fa-sms me-1"></i>Send bank details to SMS
                                            </button>
                                        </div>
                                        <div id="bankSendStatus" class="small text-muted mb-3">Choose a channel to send the bank details directly to the donor.</div>
                                        
                                    <div class="alert alert-info">
                                        <h6 class="alert-heading"><i class="fas fa-info-circle me-2"></i>Bank Account Details</h6>
                                        <p class="mb-2"><strong>For immediate transfers and payments:</strong></p>
                                        <div class="bank-details">
                                            <div class="mb-2">
                                                <strong>Account Name:</strong> LMKATH
                                            </div>
                                            <div class="mb-2">
                                                <strong>Account Number:</strong> 85455687
                                            </div>
                                            <div class="mb-2">
                                                <strong>Sort Code:</strong> 53-70-44
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="alert alert-warning">
                                        <h6 class="alert-heading"><i class="fas fa-exclamation-triangle me-2"></i>Important: Reference Number</h6>
                                        <p class="mb-2">When making a transfer, please use one of the following as your reference:</p>
                                        <div class="reference-options">
                                            <div class="mb-2">
                                                <strong>Option 1:</strong> Your full name: <strong><?php echo htmlspecialchars($donor->name); ?></strong>
                                            </div>
                                            <?php if (!empty($reference_number)): ?>
                                            <div class="mb-2">
                                                <strong>Option 2:</strong> Reference number: <strong class="text-primary"><?php echo htmlspecialchars($reference_number); ?></strong>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <small class="text-muted">This helps us identify your payment quickly.</small>
                                    </div>
                                </div>
                                
                                <!-- Cash - Representative Selection (shown when selected) -->
                                <div id="cashSection" style="display: none;">
                                    <div class="mb-3">
                                        <label class="form-label">Assigned Church</label>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="form-control" id="displayChurchName" style="background-color: #f8f9fa;">
                                                <span id="churchDisplayText">Loading...</span>
                                            </div>
                                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="showChurchSelector()" title="Change Church">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                        </div>
                                        <small class="text-muted">Church assigned in Step 2</small>
                                        
                                        <!-- Hidden church selector (shown when edit clicked) -->
                                        <div id="churchSelectorDiv" style="display: none;" class="mt-2">
                                            <select class="form-select" id="cash_church_id" name="cash_church_id" onchange="updateChurchAndReps()">
                                                <option value="">Select Church</option>
                                                <?php foreach ($churches as $church): ?>
                                                    <option value="<?php echo $church['id']; ?>" 
                                                            data-name="<?php echo htmlspecialchars($church['name'] . ($church['city'] ? ' (' . $church['city'] . ')' : '')); ?>">
                                                        <?php echo htmlspecialchars($church['name']); ?> 
                                                        <?php if ($church['city']): ?>
                                                            (<?php echo htmlspecialchars($church['city']); ?>)
                                                        <?php endif; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="button" class="btn btn-sm btn-secondary mt-2" onclick="hideChurchSelector()">Cancel</button>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="cash_representative_id" class="form-label">Select Representative <span class="text-danger">*</span></label>
                                        <select class="form-select" id="cash_representative_id" name="cash_representative_id" required>
                                            <option value="">Select Representative</option>
                                            <?php if (!empty($representatives)): ?>
                                                <?php foreach ($representatives as $rep): ?>
                                                    <option value="<?php echo $rep['id']; ?>" 
                                                            <?php echo ($donor->representative_id ?? '') == $rep['id'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($rep['name']); ?>
                                                        <?php if ($rep['is_primary']): ?>
                                                            <span class="badge bg-primary">Primary</span>
                                                        <?php endif; ?>
                                                        <?php if ($rep['role']): ?>
                                                            - <?php echo htmlspecialchars($rep['role']); ?>
                                                        <?php endif; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <option value="">No representatives available for this church</option>
                                            <?php endif; ?>
                                        </select>
                                        <small class="text-muted">The representative will collect cash payments from the donor</small>
                                    </div>
                                    
                                    <div class="alert alert-success">
                                        <i class="fas fa-check-circle me-2"></i>
                                        The donor will be assigned to this representative for cash collection.
                                    </div>
                                </div>
                                
                                <div class="mt-4 d-flex justify-content-between">
                                    <button type="button" class="btn btn-outline-secondary" onclick="goToStep(7)">
                                        <i class="fas fa-arrow-left me-2"></i>Back
                                    </button>
                                    <button type="button" class="btn btn-success btn-lg" id="btnStep6Next" onclick="submitForm()" disabled>
                                        Complete & Finish Call <i class="fas fa-check-double ms-2"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </main>
    </div>
</div>

<!-- Refusal Modal -->
<div class="modal fade" id="refusalModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="process-refusal.php" method="POST" id="refusalForm">
                <input type="hidden" name="session_id" value="<?php echo $session_id; ?>">
                <input type="hidden" name="donor_id" value="<?php echo $donor_id; ?>">
                <input type="hidden" name="queue_id" value="<?php echo $queue_id; ?>">
                <input type="hidden" name="duration_seconds" id="refusalDuration">
                
                <div class="modal-header">
                    <h5 class="modal-title">Close Case (Refused)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Reason for Refusal</label>
                        <select class="form-select" name="refusal_reason" required>
                            <option value="not_interested">Not Interested / Don't Want to Pay</option>
                            <option value="financial_hardship">Financial Hardship / Cannot Pay</option>
                            <option value="never_pledged_denies">Claims Never Pledged</option>
                            <option value="already_paid_claims">Claims Already Paid</option>
                            <option value="moved_abroad">Moved Abroad</option>
                            <option value="donor_deceased">Donor Deceased</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes (Optional)</label>
                        <textarea class="form-control" name="refusal_notes" rows="3" placeholder="Any additional details..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Close Case</button>
                </div>
            </form>
        </div>
    </div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script src="assets/call-widget.js"></script>
<script>
    // Initialize Call Widget
    document.addEventListener('DOMContentLoaded', function() {
        console.log('Initializing CallWidget...');
        
        // Widget now fetches full donor data via AJAX API
        CallWidget.init({
            sessionId: <?php echo $session_id; ?>,
            donorId: <?php echo $donor_id; ?>,
            donorName: '<?php echo addslashes($donor->name); ?>',
            donorPhone: '<?php echo addslashes($donor->phone); ?>'
        });
        
        // Ensure timer is running (it should auto-resume from localStorage, but force start if stopped)
        if (CallWidget.state.status === 'stopped') {
            console.log('Timer was stopped. Starting it now...');
            CallWidget.start();
        } else {
            console.log('Timer is already running/paused. Current status:', CallWidget.state.status);
        }
        
        // Log current state
        console.log('CallWidget state:', CallWidget.state);
        console.log('Current duration:', CallWidget.getDurationSeconds(), 'seconds');
    });
    
    // Step Logic
    function goToStep(stepNum) {
        // Update the hidden input for conversation_stage
        let stage = '';
        if (stepNum === 1) stage = 'connected';
        else if (stepNum === 2) stage = 'identity_verified';
        else if (stepNum === 3) stage = 'payment_verification';
        else if (stepNum === 4) stage = 'donor_info';
        else if (stepNum === 5) stage = 'readiness_check';
        else if (stepNum === 6) stage = 'payment_plan_selection';
        else if (stepNum === 7) stage = 'agreement_reached';
        else if (stepNum === 8) stage = 'payment_method_selected';
        
        if (stage) {
            const stageInput = document.getElementById('conversation_stage_input');
            if (stageInput) {
                stageInput.value = stage;
            }
        }
        
        if (stepNum === 7) {
            updateConfirmation();
        }
        
        document.querySelectorAll('.step-container').forEach(el => el.classList.remove('active'));
        document.getElementById('step' + stepNum).classList.add('active');
        window.scrollTo(0, 0);
    }

    function getPaidWhatsappPhone() {
        const donorPhone = document.getElementById('donorPhone').value || '';
        const useDifferent = document.getElementById('paidWhatsappDifferentNumber');
        const overrideInput = document.getElementById('paidWhatsappNumberInput');
        const overridePhone = overrideInput ? (overrideInput.value || '').trim() : '';

        if (useDifferent && useDifferent.checked && overridePhone !== '') {
            return overridePhone;
        }

        return donorPhone;
    }

    function togglePaidWhatsappNumberInput(checkbox) {
        const wrap = document.getElementById('paidWhatsappNumberWrap');
        const overrideInput = document.getElementById('paidWhatsappNumberInput');
        const statusEl = document.getElementById('paidWhatsappStatus');
        if (!wrap || !overrideInput || !statusEl) {
            return;
        }

        wrap.style.display = checkbox && checkbox.checked ? 'block' : 'none';
        overrideInput.value = checkbox && checkbox.checked ? overrideInput.value : '';
        statusEl.className = 'small text-muted mt-2';
        statusEl.textContent = 'No request sent yet.';
        document.getElementById('paidWhatsappSentInput').value = '0';
        const nextBtn = document.getElementById('btnStep3Next');
        if (nextBtn) {
            nextBtn.disabled = true;
        }
    }

    function syncPaidWhatsappNumber() {
        const useDifferent = document.getElementById('paidWhatsappDifferentNumber');
        if (!useDifferent || !useDifferent.checked) {
            return;
        }
        const nextBtn = document.getElementById('btnStep3Next');
        const statusEl = document.getElementById('paidWhatsappStatus');
        if (statusEl && !document.getElementById('paidWhatsappNumberInput').value.trim()) {
            statusEl.className = 'small text-warning mt-2';
            statusEl.textContent = 'Please enter a WhatsApp number before sending.';
        } else if (statusEl) {
            statusEl.className = 'small text-muted mt-2';
            statusEl.textContent = 'No request sent yet.';
        }
        if (nextBtn) {
            nextBtn.disabled = true;
        }
    }
    
    function setActiveChoice(stepId, clickedElement) {
        const container = document.getElementById(stepId);
        if (!container) {
            return;
        }
        
        container.querySelectorAll('.choice-card').forEach(card => {
            card.classList.remove('selected');
        });
        
        if (clickedElement) {
            clickedElement.classList.add('selected');
        }
    }
    
    function selectPaymentStatus(choice, clickedElement) {
        const isPaid = choice === 'yes';
        const donorAlreadyPaidInput = document.getElementById('donorAlreadyPaidInput');
        const paidBtn = document.getElementById('btnStep3Next');
        const paidEvidenceInput = document.getElementById('paidEvidenceInput');
        const paidWhatsAppInput = document.getElementById('paidWhatsappSentInput');
        const paidEvidenceText = document.getElementById('paidEvidenceText');
        const paidWhatsappStatus = document.getElementById('paidWhatsappStatus');
        const paidMethodInput = document.getElementById('paidPaymentMethodInput');
        
        donorAlreadyPaidInput.value = isPaid ? 'yes' : 'no';
        setActiveChoice('step2', clickedElement);
        
        if (isPaid) {
            if (paidBtn) paidBtn.disabled = true;
            if (paidEvidenceText) paidEvidenceText.value = '';
            paidEvidenceInput.value = '';
            if (paidWhatsAppInput) paidWhatsAppInput.value = '0';
            if (paidWhatsappStatus) paidWhatsappStatus.textContent = 'No request sent yet.';
            if (paidMethodInput) paidMethodInput.value = '';
            const diffWaCheckbox = document.getElementById('paidWhatsappDifferentNumber');
            const waNumberWrap = document.getElementById('paidWhatsappNumberWrap');
            const waNumberInput = document.getElementById('paidWhatsappNumberInput');
            if (diffWaCheckbox) {
                diffWaCheckbox.checked = false;
            }
            if (waNumberWrap) {
                waNumberWrap.style.display = 'none';
            }
            if (waNumberInput) {
                waNumberInput.value = '';
            }
            goToStep(4);
        } else {
            paidEvidenceInput.value = '';
            const diffWaCheckbox = document.getElementById('paidWhatsappDifferentNumber');
            const waNumberWrap = document.getElementById('paidWhatsappNumberWrap');
            const waNumberInput = document.getElementById('paidWhatsappNumberInput');
            if (diffWaCheckbox) {
                diffWaCheckbox.checked = false;
            }
            if (waNumberWrap) {
                waNumberWrap.style.display = 'none';
            }
            if (waNumberInput) {
                waNumberInput.value = '';
            }
            if (paidMethodInput) paidMethodInput.value = '';
            if (paidWhatsAppInput) paidWhatsAppInput.value = '0';
            if (paidEvidenceText) paidEvidenceText.value = '';
            if (paidWhatsappStatus) paidWhatsappStatus.textContent = 'No request sent yet.';
            goToStep(4);
        }
    }
    
    function selectPaidPaymentMethod(method, clickedElement) {
        const paidMethodInput = document.getElementById('paidPaymentMethodInput');
        const nextBtn = document.getElementById('btnStep3Next');

        if (paidMethodInput) {
            paidMethodInput.value = method;
        }
        setActiveChoice('step3', clickedElement);

        // Enable the Complete Call button as soon as a payment method is selected.
        // WhatsApp proof request is recommended but not required.
        if (nextBtn && method) {
            nextBtn.disabled = false;
        }
    }
    
function sendPaidWhatsAppRequest() {
        const phone = getPaidWhatsappPhone();
        const donorId = <?php echo (int)$donor_id; ?>;
        const method = document.getElementById('paidPaymentMethodInput').value || '';
        const btn = document.getElementById('sendPaidWhatsAppBtn');
        const statusEl = document.getElementById('paidWhatsappStatus');
        const paidWhsInput = document.getElementById('paidWhatsappSentInput');
        const evidenceText = document.getElementById('paidEvidenceText').value || '';
        const sessionInput = document.querySelector('input[name="session_id"]');
        const sessionId = sessionInput ? sessionInput.value : '';
        
        if (!donorId) {
            alert('Missing donor information. Please reopen the call.');
            return;
        }
        
        if (!method) {
            alert('Please choose how they paid before sending WhatsApp request.');
            return;
        }

        if (!phone) {
            alert('Please provide a WhatsApp number.');
            return;
        }
        
        if (!btn || !statusEl) {
            alert('UI components are not ready. Please refresh and try again.');
            return;
        }
        
        btn.disabled = true;
        statusEl.className = 'small text-info';
        statusEl.textContent = 'Sending WhatsApp request...';
        
        const payload = new URLSearchParams({
            donor_id: donorId.toString(),
            phone: phone,
            payment_method: method,
            evidence: evidenceText,
            session_id: sessionId.toString()
        });
        
        fetch('api/send-paid-proof-request.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: payload.toString()
        })
            .then(response => response.json())
        .then(data => {
            if (data.success) {
                statusEl.className = 'small text-success';
                statusEl.textContent = data.message || 'WhatsApp request sent successfully!';
                paidWhsInput.value = '1';
                // Hide the skip notice if it was shown
                const skipNotice = document.getElementById('paidSkipNotice');
                if (skipNotice) skipNotice.classList.add('d-none');
            } else {
                throw new Error(data.error || 'Failed to send WhatsApp request.');
            }
        })
            .catch(error => {
                console.error('Paid proof WhatsApp request failed:', error);
                statusEl.className = 'small text-danger';
                statusEl.textContent = error.message || 'Failed to send WhatsApp request.';
            })
            .finally(() => {
                btn.disabled = false;
            });
    }
    
    function updatePaidEvidence(value) {
        const paidEvidenceInput = document.getElementById('paidEvidenceInput');
        if (paidEvidenceInput) {
            paidEvidenceInput.value = (value || '').trim();
        }
    }
    
function proceedFromDonorInfo() {
    const donorAlreadyPaid = document.getElementById('donorAlreadyPaidInput').value === 'yes';
    if (donorAlreadyPaid) {
        goToStep(3);
        return;
    }
    
    goToStep(5);
}
    
function goToDonorInfoBack() {
    goToStep(2);
}
    
    // Payment Method Selection
function selectPaymentMethod(method, clickedElement) {
    const paymentMethodInput = document.getElementById('paymentMethodInput');
    if (paymentMethodInput) {
        paymentMethodInput.value = method;
    }

    const nextBtn = document.getElementById('btnStep6Next');
    if (nextBtn) {
        nextBtn.disabled = false;
    }

    // Remove selected class from all cards
    document.querySelectorAll('#step6 .choice-card').forEach(card => {
        card.classList.remove('selected');
    });
        
        // Add selected class to clicked card
        if (clickedElement) {
            clickedElement.classList.add('selected');
        }
        
        // Show/hide relevant sections
        if (method === 'cash') {
            document.getElementById('bankDetailsSection').style.display = 'none';
            document.getElementById('cashSection').style.display = 'block';
            
            // Update church display from Step 2 form
            updateChurchDisplay();
            
            // Load representatives for current church
            loadCashRepresentatives();
            
            // Validate cash selection
            validateCashSelection();
        } else if (method === 'bank_transfer') {
            document.getElementById('bankDetailsSection').style.display = 'block';
            document.getElementById('cashSection').style.display = 'none';
        } else {
            document.getElementById('bankDetailsSection').style.display = 'none';
            document.getElementById('cashSection').style.display = 'none';
        }
    }

    // Send bank details to donor via selected channel
    function sendBankDetails(channel) {
        const btnWhatsapp = document.getElementById('sendBankWhatsAppBtn');
        const btnSms = document.getElementById('sendBankSmsBtn');
        const statusEl = document.getElementById('bankSendStatus');
        const donorId = <?php echo (int)$donor_id; ?>;
        const donorPhone = document.getElementById('donorPhone').value || '';
        const referenceNumber = document.getElementById('donorReferenceNumber').value || '';
        const planId = document.getElementById('selectedPlanId').value || '';
        const planDuration = document.getElementById('selectedDuration').value || '';
        const paymentDay = getPaymentDayForBankMessage();
        
        if (!donorId) {
            alert('Missing donor information. Please refresh the page and try again.');
            return;
        }
        
        if (!donorPhone) {
            alert('No phone number is available for this donor.');
            return;
        }

        btnWhatsapp.disabled = true;
        btnSms.disabled = true;
        statusEl.className = 'small text-info';
        statusEl.textContent = 'Sending bank details...';
        
        const payload = new URLSearchParams({
            donor_id: donorId.toString(),
            phone: donorPhone,
            reference_number: referenceNumber,
            channel: channel,
            plan_id: planId,
            plan_duration: planDuration,
            payment_day: paymentDay
        });
        
        fetch('api/send-bank-details.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: payload.toString()
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                statusEl.className = 'small text-success';
                statusEl.textContent = 'Bank details sent successfully to ' + (channel === 'whatsapp' ? 'WhatsApp' : 'SMS') + '.';
            } else {
                throw new Error(data.error || 'Failed to send bank details.');
            }
            })
            .catch(error => {
                console.error('Bank details send error:', error);
                statusEl.className = 'small text-danger';
                statusEl.textContent = error.message;
            })
            .finally(() => {
            btnWhatsapp.disabled = false;
            btnSms.disabled = false;
        });
    }

    function getPaymentDayForBankMessage() {
        const planId = document.getElementById('selectedPlanId').value || '';
        const startDateInput = document.getElementById('startDate');
        let paymentDay = '';
        
        if (startDateInput && startDateInput.value) {
            const parsedDate = new Date(startDateInput.value);
            if (!Number.isNaN(parsedDate.getTime())) {
                const options = { day: 'numeric', month: 'short', year: 'numeric' };
                paymentDay = parsedDate.toLocaleDateString('en-GB', options);
            }
        }
        
        if (planId === 'custom') {
            const customDay = (document.getElementById('customPaymentDay')?.value || '').trim();
            if (customDay) {
                paymentDay = `Day ${customDay} of each month`;
            }
        }
        
        return paymentDay;
    }
    
    // Validate cash selection
    function validateCashSelection() {
        const repId = document.getElementById('cash_representative_id').value;
        const btn = document.getElementById('btnStep6Next');
        
        if (repId) {
            btn.disabled = false;
        } else {
            btn.disabled = true;
        }
    }
    
    // Add validation listener for cash section
    document.addEventListener('DOMContentLoaded', function() {
        const cashRep = document.getElementById('cash_representative_id');
        if (cashRep) {
            cashRep.addEventListener('change', validateCashSelection);
        }
    });
    
    // Get current church from Step 2 form (even if not saved to DB yet)
    function getCurrentChurch() {
        const step2Church = document.getElementById('church_id');
        if (step2Church && step2Church.value) {
            return step2Church.value;
        }
        // Fallback to existing church_id from database
        return <?php echo $donor->church_id ?? 0; ?>;
    }
    
    // Update church display based on Step 2 form value
    function updateChurchDisplay() {
        const churchId = getCurrentChurch();
        const churchSelect = document.getElementById('cash_church_id');
        const displayText = document.getElementById('churchDisplayText');
        
        if (churchId && churchSelect) {
            // Find the option with this value
            const option = churchSelect.querySelector(`option[value="${churchId}"]`);
            if (option) {
                displayText.textContent = option.getAttribute('data-name') || option.text;
                churchSelect.value = churchId;
            } else {
                displayText.textContent = 'Not assigned';
            }
        } else {
            displayText.textContent = 'Not assigned';
        }
    }
    
    // Show church selector
    function showChurchSelector() {
        // Set current value from Step 2
        const currentChurchId = getCurrentChurch();
        const churchSelect = document.getElementById('cash_church_id');
        if (churchSelect && currentChurchId) {
            churchSelect.value = currentChurchId;
        }
        document.getElementById('churchSelectorDiv').style.display = 'block';
    }
    
    // Hide church selector
    function hideChurchSelector() {
        document.getElementById('churchSelectorDiv').style.display = 'none';
        updateChurchDisplay(); // Refresh display
    }
    
    // Update church display and load representatives
    function updateChurchAndReps() {
        const churchSelect = document.getElementById('cash_church_id');
        const selectedOption = churchSelect.options[churchSelect.selectedIndex];
        const churchName = selectedOption.getAttribute('data-name') || selectedOption.text;
        const churchId = churchSelect.value;
        
        // Update display
        document.getElementById('churchDisplayText').textContent = churchName || 'Not assigned';
        document.getElementById('churchSelectorDiv').style.display = 'none';
        
        // Also update Step 2 church_id so it gets saved
        const step2Church = document.getElementById('church_id');
        if (step2Church) {
            step2Church.value = churchId;
        }
        
        // Load representatives
        if (churchId) {
            loadRepresentativesForChurch(churchId);
        } else {
            document.getElementById('cash_representative_id').innerHTML = '<option value="">Select Representative</option>';
        }
    }
    
    // Load representatives for a specific church
    function loadRepresentativesForChurch(churchId) {
        document.getElementById('cash_representative_id').innerHTML = '<option value="">Loading...</option>';
        
        fetch(`get-representatives.php?church_id=${churchId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.representatives) {
                    let html = '<option value="">Select Representative</option>';
                    data.representatives.forEach(rep => {
                        html += `<option value="${rep.id}">${rep.name}${rep.is_primary ? ' <span class="badge bg-primary">Primary</span>' : ''}${rep.role ? ' - ' + rep.role : ''}</option>`;
                    });
                    document.getElementById('cash_representative_id').innerHTML = html;
                    validateCashSelection();
                } else {
                    document.getElementById('cash_representative_id').innerHTML = '<option value="">No representatives found</option>';
                }
            })
            .catch(error => {
                console.error('Error loading representatives:', error);
                document.getElementById('cash_representative_id').innerHTML = '<option value="">Error loading representatives</option>';
            });
    }
    
    // Load representatives when cash is selected
    function loadCashRepresentatives() {
        // Get church_id from Step 2 form (prioritize form value over DB value)
        const churchId = getCurrentChurch();
        
        if (churchId) {
            loadRepresentativesForChurch(churchId);
        } else {
            document.getElementById('cash_representative_id').innerHTML = '<option value="">Please assign a church in Step 2 first</option>';
        }
    }
    
    // Filter church dropdown by city and search text
    function filterChurchOptions() {
        const churchSelect = document.getElementById('church_id');
        if (!churchSelect) {
            return;
        }
        
        const cityFilter = (document.getElementById('church_city_filter')?.value || '').toLowerCase();
        const searchFilter = (document.getElementById('church_search')?.value || '').trim().toLowerCase();
        const selectedValue = churchSelect.value;
        const countEl = document.getElementById('churchFilterCount');
        let visibleCount = 0;
        
        churchSelect.querySelectorAll('option.church-option').forEach(option => {
            const optionCity = ((option.getAttribute('data-city') || '').toLowerCase());
            const searchText = ((option.getAttribute('data-search') || '').toLowerCase());
            const matchesCity = !cityFilter || optionCity === cityFilter;
            const matchesSearch = !searchFilter || searchText.includes(searchFilter);
            const isSelected = option.value === selectedValue;
            const visible = (matchesCity && matchesSearch) || isSelected;
            
            option.hidden = !visible;
            if (visible) {
                visibleCount++;
            }
        });
        
        if (countEl) {
            countEl.textContent = visibleCount > 0
                ? `${visibleCount} churches shown`
                : 'No churches match your filters';
        }
    }
    
    // Clear church search filters
    function clearChurchFilters() {
        const cityFilter = document.getElementById('church_city_filter');
        const searchFilter = document.getElementById('church_search');
        
        if (cityFilter) {
            cityFilter.value = '';
        }
        if (searchFilter) {
            searchFilter.value = '';
        }
        
        filterChurchOptions();
    }
    
    // Watch Step 2 church_id changes and update Step 6 display
    document.addEventListener('DOMContentLoaded', function() {
        const step2Church = document.getElementById('church_id');
        if (step2Church) {
            step2Church.addEventListener('change', function() {
                // If Step 6 is visible and cash is selected, update display
                const cashSection = document.getElementById('cashSection');
                if (cashSection && cashSection.style.display !== 'none') {
                    updateChurchDisplay();
                    loadCashRepresentatives();
                }
            });
        }
        
        filterChurchOptions();
        
        const cityFilter = document.getElementById('church_city_filter');
        const searchFilter = document.getElementById('church_search');
        if (cityFilter) {
            cityFilter.addEventListener('change', filterChurchOptions);
        }
        if (searchFilter) {
            searchFilter.addEventListener('input', filterChurchOptions);
        }
    });
    
    // Step 1 Validation
    const verifyAmount = document.getElementById('verifyAmount');
    const verifyLocation = document.getElementById('verifyLocation');
    const btnStep1Next = document.getElementById('btnStep1Next');
    
    function checkStep1() {
        const amountChecked = verifyAmount.checked;
        const locationChecked = verifyLocation ? verifyLocation.checked : true;
        
        if (amountChecked && locationChecked) {
            btnStep1Next.disabled = false;
        } else {
            btnStep1Next.disabled = true;
        }
    }
    
    if(verifyAmount) verifyAmount.addEventListener('change', checkStep1);
    if(verifyLocation) verifyLocation.addEventListener('change', checkStep1);
    
    // Step 2 Logic - Update field badges when values change
    function updateFieldBadge(fieldName, newValue) {
        // Map field names to badge IDs and input IDs
        const fieldMap = {
            'baptism_name': { badge: 'badge-baptism-name', input: 'baptism_name' },
            'city': { badge: 'badge-city', input: 'city' },
            'email': { badge: 'badge-email', input: 'email' },
            'church': { badge: 'badge-church', input: 'church_id' },
            'language': { badge: 'badge-language', input: 'preferred_language' }
        };
        
        const config = fieldMap[fieldName];
        if (!config) return;
        
        const badge = document.getElementById(config.badge);
        const input = document.getElementById(config.input);
        
        if (badge && input) {
            const originalValue = input.getAttribute('data-original-value') || '';
            const currentValue = newValue || input.value;
            
            if (currentValue !== originalValue && currentValue !== '' && originalValue !== '') {
                // Value was changed from original
                badge.className = 'badge bg-warning ms-2';
                badge.innerHTML = '<i class="fas fa-edit me-1"></i>Updated';
                badge.style.display = 'inline-block';
                input.classList.remove('border-success');
                input.classList.add('border-warning');
            } else if (currentValue === originalValue && originalValue !== '') {
                // Value matches original (existing data)
                badge.className = 'badge bg-success ms-2';
                badge.innerHTML = '<i class="fas fa-check me-1"></i>Existing';
                badge.style.display = 'inline-block';
                input.classList.remove('border-warning');
                input.classList.add('border-success');
            } else if (currentValue !== '' && originalValue === '') {
                // New value entered (no original data)
                badge.style.display = 'none';
                input.classList.remove('border-success', 'border-warning');
            } else {
                // Empty value
                badge.style.display = 'none';
                input.classList.remove('border-success', 'border-warning');
            }
        }
    }
    
    // Store original values for comparison
    document.addEventListener('DOMContentLoaded', function() {
        const fields = [
            { id: 'baptism_name', badge: 'badge-baptism-name' },
            { id: 'city', badge: 'badge-city' },
            { id: 'email', badge: 'badge-email' },
            { id: 'church_id', badge: 'badge-church' },
            { id: 'preferred_language', badge: 'badge-language' }
        ];
        
        fields.forEach(function(field) {
            const input = document.getElementById(field.id);
            const badge = document.getElementById(field.badge);
            
            if (input) {
                const originalValue = input.value || '';
                input.setAttribute('data-original-value', originalValue);
                
                // Hide badge if no original value
                if (badge && originalValue === '') {
                    badge.style.display = 'none';
                }
            }
        });
    });
    
    // Step 3 Logic (Payment Readiness)
    function selectReadiness(choice) {
        document.getElementById('readyToPayInput').value = choice;
        if (choice === 'yes') {
            goToStep(8);
        } else if (choice === 'refused') {
            // Capture duration
            let duration = 0;
            try {
                duration = CallWidget.getDurationSeconds();
            } catch(e) { console.error(e); }
            
            document.getElementById('refusalDuration').value = duration;
            CallWidget.pause();
            
            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('refusalModal'));
            modal.show();
        } else {
            window.location.href = 'schedule-callback.php?session_id=<?php echo $session_id; ?>&donor_id=<?php echo $donor_id; ?>&queue_id=<?php echo $queue_id; ?>&status=not_ready_to_pay';
        }
    }
    
    // Step 3 Plan Logic
    const donorBalance = <?php echo (float)$donor->balance; ?>;
    let selectedDuration = 0;
    let planDetails = {
        amount: 0,
        frequency: 'Monthly',
        count: 0,
        start: '',
        end: '',
        is_one_time: false
    };

    function isOneTimePlan(planId) {
        return planId === 'def_0';
    }
    
    function selectPlan(id, duration) {
        // Update UI
        document.querySelectorAll('.plan-card').forEach(el => el.classList.remove('selected'));
        event.currentTarget.classList.add('selected');
        
        // Update Inputs
        document.getElementById('selectedPlanId').value = id;
        
        const summaryBox = document.getElementById('planSummaryBox');
        const placeholder = document.getElementById('planPlaceholder');
        const customOptions = document.getElementById('customPlanOptions');
        
        placeholder.style.display = 'none';
        summaryBox.classList.add('active');
        
        if (id === 'custom') {
            customOptions.style.display = 'block';
            // Auto-set the recurring payment day from the start date
            syncPaymentDayFromStartDate();
            // Initial calc for custom
            calculatePreview();
        } else {
            customOptions.style.display = 'none';
            selectedDuration = duration;
            if (isOneTimePlan(id)) {
                selectedDuration = 1;
            }
            // Standard logic for fixed plans
            document.getElementById('selectedDuration').value = selectedDuration;
            planDetails.is_one_time = isOneTimePlan(id);
            calculatePreview(true); // true = use selectedDuration
        }
        
        // On mobile, scroll to summary
        if (window.innerWidth < 992) {
            summaryBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }
    
    // Auto-sync recurring payment day from start date
    function syncPaymentDayFromStartDate() {
        const startDateInput = document.getElementById('startDate');
        const paymentDayInput = document.getElementById('customPaymentDay');
        
        if (startDateInput && startDateInput.value && paymentDayInput) {
            const startDate = new Date(startDateInput.value);
            let day = startDate.getDate();
            // Cap at 28 to avoid month-end issues
            if (day > 28) day = 28;
            paymentDayInput.value = day;
        }
    }
    
    // Listen for start date changes to sync payment day
    document.addEventListener('DOMContentLoaded', function() {
        const startDateInput = document.getElementById('startDate');
        if (startDateInput) {
            startDateInput.addEventListener('change', function() {
                // Only sync if custom plan is selected
                if (document.getElementById('selectedPlanId').value === 'custom') {
                    syncPaymentDayFromStartDate();
                }
            });
        }
    });
    
    function adjustPayments(delta) {
        const input = document.getElementById('customPayments');
        let val = parseInt(input.value) || 12;
        val += delta;
        if (val < 1) val = 1;
        if (val > 120) val = 120;
        input.value = val;
        calculatePreview();
    }
    
    function calculatePreview(isStandard = false) {
        let frequencyUnit = 'month';
        let frequencyNum = 1;
        let count = selectedDuration; // Default for standard
        let amountLabel = 'Monthly';
        const selectedPlanId = document.getElementById('selectedPlanId').value;
        const isOneTime = isOneTimePlan(selectedPlanId);
        
        if (!isStandard && document.getElementById('selectedPlanId').value === 'custom') {
            frequencyUnit = document.getElementById('customFreqUnit').value;
            frequencyNum = parseInt(document.getElementById('customFreqNumber').value) || 1;
            count = parseInt(document.getElementById('customPayments').value) || 12;
            
            // Update label based on frequency
            if (frequencyNum === 1) {
                amountLabel = frequencyUnit.charAt(0).toUpperCase() + frequencyUnit.slice(1) + 'ly'; // Monthly, Weekly...
                if (amountLabel === 'Dayly') amountLabel = 'Daily';
            } else {
                amountLabel = `Every ${frequencyNum} ${frequencyUnit}s`;
            }
        } else {
            // Standard plan is always monthly
            amountLabel = 'Monthly';
            if (isOneTime) {
                amountLabel = 'One-time';
            }
        }
        
        document.getElementById('previewAmountLabel').textContent = amountLabel;
        
        // Calculate Installment Amount
        const installment = donorBalance / count;
        
        // Store details for Step 4
        planDetails.amount = installment;
        planDetails.frequency = amountLabel;
        planDetails.count = count;
        planDetails.is_one_time = isOneTime;
        
        // Update UI
        document.getElementById('previewMonthly').textContent = '£' + installment.toLocaleString('en-GB', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        document.getElementById('previewCount').textContent = count;
        
        // Calculate Dates
        const startDateInput = document.getElementById('startDate').value;
        if (startDateInput) {
            const start = new Date(startDateInput);
            let end = new Date(startDateInput);
            
            // Calculate End Date based on Frequency * Count
            // We add (count - 1) intervals
            const intervals = count - 1;
            
            if (frequencyUnit === 'day') {
                end.setDate(end.getDate() + (intervals * frequencyNum));
            } else if (frequencyUnit === 'week') {
                end.setDate(end.getDate() + (intervals * frequencyNum * 7));
            } else if (frequencyUnit === 'month') {
                end.setMonth(end.getMonth() + (intervals * frequencyNum));
            } else if (frequencyUnit === 'year') {
                end.setFullYear(end.getFullYear() + (intervals * frequencyNum));
            }
            
            const options = { day: 'numeric', month: 'short', year: 'numeric' };
            const startStr = start.toLocaleDateString('en-GB', options);
            const endStr = end.toLocaleDateString('en-GB', options);
            
            document.getElementById('previewFirstDate').textContent = startStr;
            document.getElementById('previewLastDate').textContent = endStr;
            
            planDetails.start = startStr;
            planDetails.end = endStr;
        }
        
        document.getElementById('btnReview').disabled = false;
    }
    
    function updateConfirmation() {
        document.getElementById('confFrequency').textContent = planDetails.frequency;
        document.getElementById('confInstallment').textContent = '£' + planDetails.amount.toLocaleString('en-GB', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        document.getElementById('confCount').textContent = planDetails.count;
        document.getElementById('confStart').textContent = planDetails.start;
        document.getElementById('confEnd').textContent = planDetails.end;
        
        // Get payment day - only show for custom plans
        const selectedPlanId = document.getElementById('selectedPlanId').value;
        const confPaymentDayEl = document.getElementById('confPaymentDay');
        
        if (selectedPlanId === 'custom') {
            // Custom plan: show the configured payment day
            const paymentDayInput = document.getElementById('customPaymentDay');
            const paymentDay = paymentDayInput ? paymentDayInput.value : '';
            confPaymentDayEl.textContent = paymentDay ? 'Day ' + paymentDay + ' of each month' : 'Same as start date';
        } else if (selectedPlanId === 'def_0') {
            confPaymentDayEl.textContent = 'One-time payment';
        } else {
            // Standard plan: extract day from start date
            const startDateInput = document.getElementById('startDate');
            if (startDateInput && startDateInput.value) {
                const startDate = new Date(startDateInput.value);
                const day = startDate.getDate();
                confPaymentDayEl.textContent = 'Day ' + day + ' (from start date)';
            } else {
                confPaymentDayEl.textContent = 'Monthly';
            }
        }
        
        // Update text
        document.getElementById('confTextAmount').textContent = '£' + planDetails.amount.toLocaleString('en-GB', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        document.getElementById('confTextFreq').textContent = planDetails.is_one_time ? 'once' : planDetails.frequency.toLowerCase();
        document.getElementById('confTextDate').textContent = planDetails.start;
    }
    
function submitForm() {
    console.log('submitForm called');
    const donorAlreadyPaid = document.getElementById('donorAlreadyPaidInput')?.value === 'yes';
    
    // Validate payment method is selected
    if (!donorAlreadyPaid) {
        const paymentMethod = document.getElementById('paymentMethodInput').value;
        if (!paymentMethod) {
            alert('Please select a payment method before completing the call.');
            goToStep(6);
            return;
        }
    }
    
    // If cash is selected, validate representative
    const paymentMethod = donorAlreadyPaid ? 'already_paid' : document.getElementById('paymentMethodInput').value;
    if (paymentMethod === 'cash') {
        const repId = document.getElementById('cash_representative_id').value;
        
        if (!repId) {
            alert('Please select a representative for cash payments.');
            goToStep(8);
                return;
            }
        }
        
        // Check if CallWidget exists
        if (typeof CallWidget === 'undefined') {
            console.error('CallWidget is not defined!');
            alert('ERROR: Call Widget not loaded. Duration will be 0.');
            // Continue anyway to allow form submission
        }
        
        // Get duration from widget
        let duration = 0;
        try {
            duration = CallWidget.getDurationSeconds();
            console.log('Duration from CallWidget:', duration);
        } catch (e) {
            console.error('Error getting duration:', e);
            alert('ERROR: Could not get call duration. Error: ' + e.message);
        }
        
        // Show confirmation with duration
        console.log('Final duration to be saved:', duration, 'seconds');
        
        // Create hidden input for duration
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'call_duration_seconds';
        input.value = duration;
        
        const form = document.getElementById('conversationForm');
        form.appendChild(input);
        
        console.log('Hidden input added to form:', input);
        console.log('Form data:', new FormData(form));
        
        // Stop timer and clear state
        try {
            CallWidget.pause();
            CallWidget.resetState();
            console.log('CallWidget stopped and reset');
        } catch (e) {
            console.error('Error stopping CallWidget:', e);
        }
        
        form.submit();
    }
</script>
</body>
</html>
