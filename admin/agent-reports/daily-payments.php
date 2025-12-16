<?php
/**
 * Agent Daily Payments Report
 * 
 * Shows payments due for a specific date for donors assigned to the logged-in agent.
 * Displays who paid and who missed their payment.
 * Fully mobile responsive.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

// Allow both admin and registrar access
$user = current_user();
if (!in_array($user['role'] ?? '', ['admin', 'registrar'])) {
    header('Location: ' . url_for('index.php'));
    exit;
}

$db = db();
$userId = (int)$user['id'];
$userName = $user['name'] ?? 'Agent';
$isAdmin = ($user['role'] ?? '') === 'admin';

// Get selected date (default to today)
$selectedDate = $_GET['date'] ?? date('Y-m-d');
$selectedDateFormatted = date('l, j F Y', strtotime($selectedDate));
$isToday = $selectedDate === date('Y-m-d');
$isPast = $selectedDate < date('Y-m-d');
$isFuture = $selectedDate > date('Y-m-d');

// Check if pledge_payments has payment_plan_id column
$hasPlanIdCol = false;
$checkCol = $db->query("SHOW COLUMNS FROM pledge_payments LIKE 'payment_plan_id'");
if ($checkCol && $checkCol->num_rows > 0) {
    $hasPlanIdCol = true;
}

/**
 * Query payments due on selected date for donors assigned to this agent.
 * Admin can see all donors if they have the 'view_all' filter.
 */
$viewAll = $isAdmin && isset($_GET['view']) && $_GET['view'] === 'all';

$query = "
    SELECT 
        pp.id as plan_id,
        pp.donor_id,
        pp.pledge_id,
        pp.monthly_amount,
        pp.next_payment_due,
        pp.payment_method,
        pp.plan_frequency_unit,
        pp.plan_frequency_number,
        d.name as donor_name,
        d.phone as donor_phone,
        d.agent_id,
        d.preferred_language,
        u.name as agent_name,
        pl.notes as pledge_notes
    FROM donor_payment_plans pp
    JOIN donors d ON pp.donor_id = d.id
    LEFT JOIN users u ON d.agent_id = u.id
    LEFT JOIN pledges pl ON pp.pledge_id = pl.id
    WHERE pp.next_payment_due = ?
    AND pp.status = 'active'
";

// Filter by agent unless admin viewing all
if (!$viewAll) {
    $query .= " AND d.agent_id = ?";
}

$query .= " ORDER BY d.name ASC";

if ($viewAll) {
    $stmt = $db->prepare($query);
    $stmt->bind_param('s', $selectedDate);
} else {
    $stmt = $db->prepare($query);
    $stmt->bind_param('si', $selectedDate, $userId);
}
$stmt->execute();
$duePayments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Now check which payments were actually made
$paidDonors = [];
$unpaidDonors = [];
$totalDueAmount = 0.0;
$totalPaidAmount = 0.0;
$totalUnpaidAmount = 0.0;

foreach ($duePayments as $payment) {
    $donorId = (int)$payment['donor_id'];
    $planId = (int)$payment['plan_id'];
    $amount = (float)$payment['monthly_amount'];
    $totalDueAmount += $amount;
    
    // Check if payment was made
    $paymentMade = false;
    $paymentDetails = null;
    
    if ($hasPlanIdCol) {
        $checkPay = $db->prepare("
            SELECT id, amount, payment_method, payment_date, status, reference_number
            FROM pledge_payments 
            WHERE payment_plan_id = ? 
            AND DATE(payment_date) = ?
            AND status IN ('pending', 'approved')
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $checkPay->bind_param('is', $planId, $selectedDate);
    } else {
        $checkPay = $db->prepare("
            SELECT id, amount, payment_method, payment_date, status, reference_number
            FROM pledge_payments 
            WHERE donor_id = ? 
            AND DATE(payment_date) = ?
            AND status IN ('pending', 'approved')
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $checkPay->bind_param('is', $donorId, $selectedDate);
    }
    $checkPay->execute();
    $paymentResult = $checkPay->get_result()->fetch_assoc();
    
    if ($paymentResult) {
        $paymentMade = true;
        $paymentDetails = $paymentResult;
        $totalPaidAmount += $amount;
        
        $paidDonors[] = [
            'donor_id' => $donorId,
            'donor_name' => $payment['donor_name'],
            'donor_phone' => $payment['donor_phone'] ?? '',
            'amount_due' => $amount,
            'amount_paid' => (float)$paymentResult['amount'],
            'payment_method' => $paymentResult['payment_method'] ?? 'unknown',
            'payment_status' => $paymentResult['status'],
            'reference' => $paymentResult['reference_number'] ?? '',
            'agent_name' => $payment['agent_name'] ?? 'Unassigned'
        ];
    } else {
        $totalUnpaidAmount += $amount;
        
        $unpaidDonors[] = [
            'donor_id' => $donorId,
            'donor_name' => $payment['donor_name'],
            'donor_phone' => $payment['donor_phone'] ?? '',
            'amount_due' => $amount,
            'payment_method' => $payment['payment_method'] ?? 'bank_transfer',
            'agent_name' => $payment['agent_name'] ?? 'Unassigned'
        ];
    }
}

// Calculate stats
$totalDonors = count($duePayments);
$paidCount = count($paidDonors);
$unpaidCount = count($unpaidDonors);
$completionRate = $totalDonors > 0 ? round(($paidCount / $totalDonors) * 100) : 0;

// Get navigation dates
$prevDate = date('Y-m-d', strtotime($selectedDate . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($selectedDate . ' +1 day'));

$page_title = 'Daily Payments Report';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($page_title); ?> - Fundraising</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #0a6286;
            --primary-dark: #084a66;
            --primary-light: #e8f4f8;
            --success-color: #10b981;
            --success-light: #d1fae5;
            --danger-color: #ef4444;
            --danger-light: #fee2e2;
            --warning-color: #f59e0b;
            --warning-light: #fef3c7;
        }
        
        * {
            box-sizing: border-box;
        }
        
        body {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            padding-bottom: env(safe-area-inset-bottom);
        }
        
        /* Header */
        .page-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 16px 16px 20px;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        
        .back-btn {
            color: white;
            text-decoration: none;
            font-size: 1.2rem;
            padding: 8px;
            margin: -8px;
            border-radius: 8px;
            transition: background 0.2s;
        }
        
        .back-btn:hover {
            background: rgba(255,255,255,0.1);
            color: white;
        }
        
        .agent-badge {
            background: rgba(255,255,255,0.2);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
        }
        
        .page-title {
            font-size: 1.3rem;
            font-weight: 700;
            margin: 0;
            text-align: center;
        }
        
        /* Date Navigation */
        .date-nav {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            background: rgba(255,255,255,0.1);
            border-radius: 12px;
            padding: 8px 12px;
        }
        
        .date-nav-btn {
            color: white;
            background: rgba(255,255,255,0.15);
            border: none;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .date-nav-btn:hover {
            background: rgba(255,255,255,0.3);
            color: white;
        }
        
        .current-date {
            font-weight: 600;
            font-size: 0.95rem;
            text-align: center;
            min-width: 180px;
        }
        
        .current-date small {
            display: block;
            font-size: 0.75rem;
            opacity: 0.8;
            font-weight: 400;
        }
        
        .today-badge {
            background: var(--warning-color);
            color: #000;
            font-size: 0.65rem;
            padding: 2px 6px;
            border-radius: 4px;
            text-transform: uppercase;
            font-weight: 700;
            margin-left: 6px;
        }
        
        /* Main Content */
        .main-content {
            padding: 16px;
            max-width: 600px;
            margin: 0 auto;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 16px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        
        .stat-card.success {
            border-left: 4px solid var(--success-color);
        }
        
        .stat-card.danger {
            border-left: 4px solid var(--danger-color);
        }
        
        .stat-card.primary {
            border-left: 4px solid var(--primary-color);
        }
        
        .stat-card.warning {
            border-left: 4px solid var(--warning-color);
        }
        
        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            line-height: 1;
        }
        
        .stat-card.success .stat-value { color: var(--success-color); }
        .stat-card.danger .stat-value { color: var(--danger-color); }
        .stat-card.primary .stat-value { color: var(--primary-color); }
        .stat-card.warning .stat-value { color: var(--warning-color); }
        
        .stat-label {
            font-size: 0.75rem;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 4px;
        }
        
        .stat-amount {
            font-size: 0.85rem;
            color: #374151;
            margin-top: 2px;
        }
        
        /* Progress Bar */
        .progress-section {
            background: white;
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        
        .progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .progress-title {
            font-weight: 600;
            color: #374151;
        }
        
        .progress-percent {
            font-weight: 700;
            font-size: 1.1rem;
        }
        
        .progress {
            height: 12px;
            border-radius: 6px;
            background: #e5e7eb;
        }
        
        .progress-bar {
            border-radius: 6px;
            transition: width 0.5s ease;
        }
        
        /* Section Headers */
        .section-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
            padding: 0 4px;
        }
        
        .section-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.9rem;
        }
        
        .section-icon.success { background: var(--success-color); }
        .section-icon.danger { background: var(--danger-color); }
        
        .section-title {
            font-weight: 600;
            color: #374151;
            font-size: 1rem;
        }
        
        .section-count {
            margin-left: auto;
            background: #f3f4f6;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
            color: #6b7280;
        }
        
        /* Donor Cards */
        .donor-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-bottom: 24px;
        }
        
        .donor-card {
            background: white;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .donor-card:active {
            transform: scale(0.98);
        }
        
        .donor-card.paid {
            border-left: 4px solid var(--success-color);
        }
        
        .donor-card.unpaid {
            border-left: 4px solid var(--danger-color);
        }
        
        .donor-card-link {
            display: block;
            padding: 14px;
            text-decoration: none;
            color: inherit;
        }
        
        .donor-card-link:hover {
            color: inherit;
        }
        
        .donor-main {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 8px;
        }
        
        .donor-name {
            font-weight: 600;
            color: #1f2937;
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .donor-name .status-badge {
            font-size: 0.65rem;
            padding: 2px 6px;
            border-radius: 4px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-badge.paid {
            background: var(--success-light);
            color: var(--success-color);
        }
        
        .status-badge.pending {
            background: var(--warning-light);
            color: #b45309;
        }
        
        .status-badge.missed {
            background: var(--danger-light);
            color: var(--danger-color);
        }
        
        .donor-amount {
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--primary-color);
        }
        
        .donor-details {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            font-size: 0.8rem;
            color: #6b7280;
        }
        
        .donor-detail {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .donor-detail i {
            width: 14px;
            text-align: center;
            font-size: 0.75rem;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #9ca3af;
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 16px;
            opacity: 0.5;
        }
        
        .empty-state h3 {
            color: #6b7280;
            font-size: 1.1rem;
            margin-bottom: 8px;
        }
        
        .empty-state p {
            font-size: 0.9rem;
            margin: 0;
        }
        
        /* View Toggle (Admin) */
        .view-toggle {
            display: flex;
            background: white;
            border-radius: 12px;
            padding: 4px;
            margin-bottom: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        
        .view-toggle-btn {
            flex: 1;
            padding: 10px;
            border: none;
            background: transparent;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.85rem;
            color: #6b7280;
            text-decoration: none;
            text-align: center;
            transition: all 0.2s;
        }
        
        .view-toggle-btn.active {
            background: var(--primary-color);
            color: white;
        }
        
        .view-toggle-btn:hover:not(.active) {
            background: #f3f4f6;
            color: #374151;
        }
        
        /* Call Button */
        .call-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            background: var(--success-light);
            color: var(--success-color);
            border-radius: 50%;
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .call-btn:hover {
            background: var(--success-color);
            color: white;
        }
        
        /* Floating Date Picker */
        .date-picker-trigger {
            position: fixed;
            bottom: 80px;
            right: 16px;
            width: 56px;
            height: 56px;
            background: var(--primary-color);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            box-shadow: 0 4px 12px rgba(10, 98, 134, 0.4);
            border: none;
            cursor: pointer;
            z-index: 50;
        }
        
        .date-picker-trigger:hover {
            background: var(--primary-dark);
            transform: scale(1.05);
        }
        
        /* Back to top */
        .back-nav {
            position: fixed;
            bottom: 16px;
            left: 16px;
            right: 16px;
            max-width: 568px;
            margin: 0 auto;
            background: white;
            border-radius: 16px;
            padding: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            display: flex;
            gap: 10px;
            z-index: 50;
        }
        
        .nav-btn {
            flex: 1;
            padding: 12px;
            border-radius: 12px;
            border: none;
            font-weight: 600;
            font-size: 0.85rem;
            text-decoration: none;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: all 0.2s;
        }
        
        .nav-btn.primary {
            background: var(--primary-color);
            color: white;
        }
        
        .nav-btn.secondary {
            background: #f3f4f6;
            color: #374151;
        }
        
        .nav-btn:hover {
            transform: translateY(-2px);
        }
        
        /* Date Input Modal */
        .date-modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 200;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .date-modal.show {
            display: flex;
        }
        
        .date-modal-content {
            background: white;
            border-radius: 20px;
            padding: 24px;
            width: 100%;
            max-width: 320px;
            text-align: center;
        }
        
        .date-modal h4 {
            margin: 0 0 16px;
            color: #1f2937;
        }
        
        .date-modal input[type="date"] {
            width: 100%;
            padding: 14px;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            font-size: 1rem;
            margin-bottom: 16px;
        }
        
        .date-modal-actions {
            display: flex;
            gap: 10px;
        }
        
        .date-modal-actions button {
            flex: 1;
            padding: 12px;
            border-radius: 10px;
            border: none;
            font-weight: 600;
            cursor: pointer;
        }
        
        .date-modal-actions .go-btn {
            background: var(--primary-color);
            color: white;
        }
        
        .date-modal-actions .cancel-btn {
            background: #f3f4f6;
            color: #374151;
        }
        
        /* Responsive */
        @media (max-width: 380px) {
            .stats-grid {
                grid-template-columns: 1fr 1fr;
                gap: 8px;
            }
            
            .stat-card {
                padding: 12px;
            }
            
            .stat-value {
                font-size: 1.5rem;
            }
            
            .current-date {
                min-width: 140px;
                font-size: 0.85rem;
            }
        }
        
        /* Safe area for fixed nav */
        .main-content {
            padding-bottom: 100px;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="page-header">
        <div class="header-top">
            <a href="../donor-management/payment-calendar.php" class="back-btn">
                <i class="fas fa-arrow-left"></i>
            </a>
            <h1 class="page-title">Daily Report</h1>
            <span class="agent-badge">
                <i class="fas fa-user me-1"></i><?php echo htmlspecialchars(explode(' ', $userName)[0]); ?>
            </span>
        </div>
        
        <!-- Date Navigation -->
        <div class="date-nav">
            <a href="?date=<?php echo $prevDate; ?><?php echo $viewAll ? '&view=all' : ''; ?>" class="date-nav-btn">
                <i class="fas fa-chevron-left"></i>
            </a>
            <div class="current-date">
                <span>
                    <?php echo date('l', strtotime($selectedDate)); ?>
                    <?php if ($isToday): ?>
                        <span class="today-badge">Today</span>
                    <?php endif; ?>
                </span>
                <small><?php echo date('j F Y', strtotime($selectedDate)); ?></small>
            </div>
            <a href="?date=<?php echo $nextDate; ?><?php echo $viewAll ? '&view=all' : ''; ?>" class="date-nav-btn">
                <i class="fas fa-chevron-right"></i>
            </a>
        </div>
    </header>
    
    <main class="main-content">
        <?php if ($isAdmin): ?>
        <!-- Admin View Toggle -->
        <div class="view-toggle">
            <a href="?date=<?php echo $selectedDate; ?>" class="view-toggle-btn <?php echo !$viewAll ? 'active' : ''; ?>">
                <i class="fas fa-user me-1"></i> My Donors
            </a>
            <a href="?date=<?php echo $selectedDate; ?>&view=all" class="view-toggle-btn <?php echo $viewAll ? 'active' : ''; ?>">
                <i class="fas fa-users me-1"></i> All Agents
            </a>
        </div>
        <?php endif; ?>
        
        <?php if ($totalDonors === 0): ?>
        <!-- Empty State -->
        <div class="empty-state">
            <i class="fas fa-calendar-check"></i>
            <h3>No Payments Due</h3>
            <p>
                <?php if ($viewAll): ?>
                    No payments scheduled for any agent on this date.
                <?php else: ?>
                    None of your assigned donors have payments due on this date.
                <?php endif; ?>
            </p>
        </div>
        <?php else: ?>
        
        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card primary">
                <div class="stat-value"><?php echo $totalDonors; ?></div>
                <div class="stat-label">Total Due</div>
                <div class="stat-amount">£<?php echo number_format($totalDueAmount, 2); ?></div>
            </div>
            <div class="stat-card <?php echo $completionRate >= 50 ? 'success' : 'warning'; ?>">
                <div class="stat-value"><?php echo $completionRate; ?>%</div>
                <div class="stat-label">Complete</div>
            </div>
            <div class="stat-card success">
                <div class="stat-value"><?php echo $paidCount; ?></div>
                <div class="stat-label">Paid</div>
                <div class="stat-amount">£<?php echo number_format($totalPaidAmount, 2); ?></div>
            </div>
            <div class="stat-card danger">
                <div class="stat-value"><?php echo $unpaidCount; ?></div>
                <div class="stat-label"><?php echo $isPast || $isToday ? 'Missed' : 'Pending'; ?></div>
                <div class="stat-amount">£<?php echo number_format($totalUnpaidAmount, 2); ?></div>
            </div>
        </div>
        
        <!-- Progress Bar -->
        <div class="progress-section">
            <div class="progress-header">
                <span class="progress-title">Collection Progress</span>
                <span class="progress-percent <?php echo $completionRate >= 50 ? 'text-success' : 'text-warning'; ?>">
                    <?php echo $completionRate; ?>%
                </span>
            </div>
            <div class="progress">
                <div class="progress-bar bg-success" style="width: <?php echo $completionRate; ?>%"></div>
            </div>
        </div>
        
        <?php if (!empty($paidDonors)): ?>
        <!-- Paid Section -->
        <div class="section-header">
            <div class="section-icon success">
                <i class="fas fa-check"></i>
            </div>
            <span class="section-title">Payments Received</span>
            <span class="section-count"><?php echo $paidCount; ?></span>
        </div>
        
        <div class="donor-list">
            <?php foreach ($paidDonors as $donor): ?>
            <div class="donor-card paid">
                <a href="../donor-management/view-donor.php?id=<?php echo $donor['donor_id']; ?>" class="donor-card-link">
                    <div class="donor-main">
                        <div class="donor-name">
                            <?php echo htmlspecialchars($donor['donor_name']); ?>
                            <span class="status-badge <?php echo $donor['payment_status'] === 'approved' ? 'paid' : 'pending'; ?>">
                                <?php echo $donor['payment_status'] === 'approved' ? 'Paid' : 'Pending'; ?>
                            </span>
                        </div>
                        <div class="donor-amount">£<?php echo number_format($donor['amount_paid'], 2); ?></div>
                    </div>
                    <div class="donor-details">
                        <?php if ($donor['donor_phone']): ?>
                        <span class="donor-detail">
                            <i class="fas fa-phone"></i>
                            <?php echo htmlspecialchars($donor['donor_phone']); ?>
                        </span>
                        <?php endif; ?>
                        <span class="donor-detail">
                            <i class="fas fa-credit-card"></i>
                            <?php echo ucwords(str_replace('_', ' ', $donor['payment_method'])); ?>
                        </span>
                        <?php if ($viewAll && $donor['agent_name']): ?>
                        <span class="donor-detail">
                            <i class="fas fa-user-tie"></i>
                            <?php echo htmlspecialchars($donor['agent_name']); ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($unpaidDonors)): ?>
        <!-- Unpaid Section -->
        <div class="section-header">
            <div class="section-icon danger">
                <i class="fas fa-times"></i>
            </div>
            <span class="section-title"><?php echo $isPast || $isToday ? 'Missed Payments' : 'Pending Payments'; ?></span>
            <span class="section-count"><?php echo $unpaidCount; ?></span>
        </div>
        
        <div class="donor-list">
            <?php foreach ($unpaidDonors as $donor): ?>
            <div class="donor-card unpaid">
                <a href="../donor-management/view-donor.php?id=<?php echo $donor['donor_id']; ?>" class="donor-card-link">
                    <div class="donor-main">
                        <div class="donor-name">
                            <?php echo htmlspecialchars($donor['donor_name']); ?>
                            <span class="status-badge missed">
                                <?php echo $isPast || $isToday ? 'Missed' : 'Due'; ?>
                            </span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <div class="donor-amount">£<?php echo number_format($donor['amount_due'], 2); ?></div>
                            <?php if ($donor['donor_phone']): ?>
                            <a href="tel:<?php echo htmlspecialchars($donor['donor_phone']); ?>" class="call-btn" onclick="event.stopPropagation();">
                                <i class="fas fa-phone"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="donor-details">
                        <?php if ($donor['donor_phone']): ?>
                        <span class="donor-detail">
                            <i class="fas fa-phone"></i>
                            <?php echo htmlspecialchars($donor['donor_phone']); ?>
                        </span>
                        <?php endif; ?>
                        <span class="donor-detail">
                            <i class="fas fa-credit-card"></i>
                            <?php echo ucwords(str_replace('_', ' ', $donor['payment_method'])); ?>
                        </span>
                        <?php if ($viewAll && $donor['agent_name']): ?>
                        <span class="donor-detail">
                            <i class="fas fa-user-tie"></i>
                            <?php echo htmlspecialchars($donor['agent_name']); ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <?php endif; ?>
    </main>
    
    <!-- Date Picker Trigger -->
    <button type="button" class="date-picker-trigger" onclick="openDateModal()">
        <i class="fas fa-calendar-alt"></i>
    </button>
    
    <!-- Bottom Navigation -->
    <nav class="back-nav">
        <a href="../donor-management/payment-calendar.php" class="nav-btn secondary">
            <i class="fas fa-calendar"></i>
            Calendar
        </a>
        <a href="?date=<?php echo date('Y-m-d'); ?><?php echo $viewAll ? '&view=all' : ''; ?>" class="nav-btn primary">
            <i class="fas fa-clock"></i>
            Today
        </a>
    </nav>
    
    <!-- Date Picker Modal -->
    <div class="date-modal" id="dateModal" onclick="closeDateModal(event)">
        <div class="date-modal-content" onclick="event.stopPropagation()">
            <h4><i class="fas fa-calendar-alt me-2"></i>Go to Date</h4>
            <input type="date" id="dateInput" value="<?php echo $selectedDate; ?>">
            <div class="date-modal-actions">
                <button type="button" class="cancel-btn" onclick="closeDateModal()">Cancel</button>
                <button type="button" class="go-btn" onclick="goToDate()">Go</button>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function openDateModal() {
            document.getElementById('dateModal').classList.add('show');
            document.getElementById('dateInput').focus();
        }
        
        function closeDateModal(event) {
            if (event && event.target !== event.currentTarget) return;
            document.getElementById('dateModal').classList.remove('show');
        }
        
        function goToDate() {
            const date = document.getElementById('dateInput').value;
            if (date) {
                const viewAll = <?php echo $viewAll ? 'true' : 'false'; ?>;
                window.location.href = `?date=${date}${viewAll ? '&view=all' : ''}`;
            }
        }
        
        // Keyboard support
        document.getElementById('dateInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                goToDate();
            }
        });
        
        // Escape to close modal
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeDateModal();
            }
        });
    </script>
</body>
</html>
