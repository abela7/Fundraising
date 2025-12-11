<?php
/**
 * Donor Message History
 * 
 * Search for donors and view comprehensive message history (SMS + WhatsApp)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../services/MessagingHelper.php';

require_admin();

$db = db();
$msg = new MessagingHelper($db);

// Get search term
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$donorId = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0;

// Get donor info if ID provided
$donor = null;
if ($donorId) {
    $stmt = $db->prepare("SELECT id, name, phone FROM donors WHERE id = ?");
    $stmt->bind_param('i', $donorId);
    $stmt->execute();
    $donor = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// Search for donors if search term provided
$search_results = [];
if (!empty($search_term) && !$donorId) {
    $search_param = "%{$search_term}%";
    
    // Check payment table columns for reference field
    $payment_columns = [];
    $col_query = $db->query("SHOW COLUMNS FROM payments");
    while ($col = $col_query->fetch_assoc()) {
        $payment_columns[] = $col['Field'];
    }
    $payment_ref_col = in_array('transaction_ref', $payment_columns) ? 'transaction_ref' : 'reference';
    
    // Build search query - search by name, phone, or reference number
    $search_query = "
        SELECT DISTINCT
            d.id,
            d.name,
            d.phone,
            d.total_pledged,
            d.total_paid,
            d.balance,
            -- Extract reference from pledge notes
            (SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(p.notes, ' ', -1), ' ', 1)
             FROM pledges p 
             WHERE (p.donor_id = d.id OR p.donor_phone = d.phone) 
             AND p.notes REGEXP '[0-9]{4}'
             ORDER BY p.created_at DESC LIMIT 1) as reference_number
        FROM donors d
        WHERE (
            d.name LIKE ? 
            OR d.phone LIKE ?
            OR EXISTS (
                SELECT 1 FROM pledges pl 
                WHERE (pl.donor_id = d.id OR pl.donor_phone = d.phone) 
                AND pl.notes LIKE ?
            )
            OR EXISTS (
                SELECT 1 FROM payments pay 
                WHERE (pay.donor_id = d.id OR pay.donor_phone = d.phone) 
                AND pay.{$payment_ref_col} LIKE ?
            )
        )
        ORDER BY d.name
        LIMIT 50
    ";
    
    $stmt = $db->prepare($search_query);
    if ($stmt) {
        $stmt->bind_param('ssss', $search_param, $search_param, $search_param, $search_param);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            // Extract 4-digit reference if not already extracted
            if (empty($row['reference_number'])) {
                $pledge_stmt = $db->prepare("
                    SELECT notes FROM pledges 
                    WHERE (donor_id = ? OR donor_phone = ?) 
                    AND notes REGEXP '[0-9]{4}'
                    ORDER BY created_at DESC LIMIT 1
                ");
                $pledge_stmt->bind_param('is', $row['id'], $row['phone']);
                $pledge_stmt->execute();
                $pledge_result = $pledge_stmt->get_result();
                if ($pledge_row = $pledge_result->fetch_assoc()) {
                    if (preg_match('/\b(\d{4})\b/', $pledge_row['notes'], $matches)) {
                        $row['reference_number'] = $matches[1];
                    }
                }
                // Fallback to padded donor ID
                if (empty($row['reference_number'])) {
                    $row['reference_number'] = str_pad((string)$row['id'], 4, '0', STR_PAD_LEFT);
                }
            } else {
                // Fallback to padded donor ID if still empty
                if (empty($row['reference_number'])) {
                    $row['reference_number'] = str_pad((string)$row['id'], 4, '0', STR_PAD_LEFT);
                }
            }
            
            $search_results[] = $row;
        }
        $stmt->close();
    }
}

// Get message history if donor selected
$messages = [];
$stats = [];
if ($donorId && $donor) {
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    $channelFilter = $_GET['channel'] ?? null;
    
    $messages = $msg->getDonorMessageHistory($donorId, $limit, $offset, $channelFilter);
    $stats = $msg->getDonorMessageStats($donorId);
}

// Get reference number for selected donor
$donor_reference = null;
if ($donorId && $donor) {
    $pledge_stmt = $db->prepare("
        SELECT notes FROM pledges 
        WHERE (donor_id = ? OR donor_phone = ?) 
        ORDER BY created_at DESC LIMIT 1
    ");
    $pledge_stmt->bind_param('is', $donorId, $donor['phone']);
    $pledge_stmt->execute();
    $pledge_result = $pledge_stmt->get_result();
    if ($pledge_row = $pledge_result->fetch_assoc()) {
        if (preg_match('/\b(\d{4})\b/', $pledge_row['notes'], $matches)) {
            $donor_reference = $matches[1];
        }
    }
    if (!$donor_reference) {
        $donor_reference = str_pad((string)$donorId, 4, '0', STR_PAD_LEFT);
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Message History<?= $donor ? ' - ' . htmlspecialchars($donor['name']) : '' ?></title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        :root {
            --msg-primary: #0d6efd;
            --msg-info: #0dcaf0;
            --msg-success: #198754;
            --msg-warning: #ffc107;
            --msg-danger: #dc3545;
            --msg-secondary: #6c757d;
            --msg-light: #f8f9fa;
            --msg-dark: #212529;
        }
        
        body {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            min-height: 100vh;
        }
        
        .admin-wrapper {
            min-height: 100vh;
        }
        
        /* Page Header */
        .page-header {
            background: linear-gradient(135deg, var(--msg-primary) 0%, #0a58ca 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 16px;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 20px rgba(13, 110, 253, 0.3);
        }
        
        .page-header h2 {
            margin: 0;
            font-weight: 700;
            font-size: 1.5rem;
        }
        
        .page-header p {
            margin: 0.5rem 0 0;
            opacity: 0.9;
            font-size: 0.875rem;
        }
        
        .page-header .btn-back {
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            border-radius: 10px;
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            transition: all 0.2s;
        }
        
        .page-header .btn-back:hover {
            background: rgba(255,255,255,0.3);
            color: white;
        }
        
        /* Search Section */
        .search-section {
            background: white;
            border-radius: 16px;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        }
        
        .search-input {
            border: 2px solid #e9ecef;
            border-radius: 12px;
            padding: 0.875rem 1rem;
            font-size: 1rem;
            transition: all 0.2s;
        }
        
        .search-input:focus {
            border-color: var(--msg-primary);
            box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.1);
        }
        
        .search-btn {
            background: linear-gradient(135deg, var(--msg-primary) 0%, #0a58ca 100%);
            border: none;
            border-radius: 12px;
            padding: 0.875rem 1.5rem;
            font-weight: 600;
            transition: all 0.2s;
        }
        
        .search-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(13, 110, 253, 0.3);
        }
        
        /* Donor Profile Card */
        .donor-profile {
            background: white;
            border-radius: 16px;
            padding: 1.25rem;
            margin-bottom: 1rem;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        }
        
        .donor-avatar {
            width: 56px;
            height: 56px;
            min-width: 56px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--msg-primary) 0%, #0a58ca 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.5rem;
            box-shadow: 0 4px 12px rgba(13, 110, 253, 0.3);
        }
        
        .donor-name {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--msg-dark);
            margin: 0;
        }
        
        .donor-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-top: 0.5rem;
        }
        
        .donor-meta-item {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            background: var(--msg-light);
            padding: 0.375rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8125rem;
            color: var(--msg-secondary);
        }
        
        .donor-meta-item i {
            color: var(--msg-primary);
            font-size: 0.75rem;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0.75rem;
            margin-bottom: 1rem;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1rem;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            transition: all 0.2s;
            border: 1px solid rgba(0,0,0,0.04);
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(0,0,0,0.1);
        }
        
        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.5rem;
            font-size: 1rem;
        }
        
        .stat-card.total .stat-icon {
            background: rgba(13, 110, 253, 0.1);
            color: var(--msg-primary);
        }
        
        .stat-card.sms .stat-icon {
            background: rgba(13, 202, 240, 0.15);
            color: var(--msg-info);
        }
        
        .stat-card.whatsapp .stat-icon {
            background: rgba(25, 135, 84, 0.1);
            color: var(--msg-success);
        }
        
        .stat-card.delivered .stat-icon {
            background: rgba(25, 135, 84, 0.1);
            color: var(--msg-success);
        }
        
        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 0.25rem;
        }
        
        .stat-card.total .stat-value { color: var(--msg-primary); }
        .stat-card.sms .stat-value { color: var(--msg-info); }
        .stat-card.whatsapp .stat-value { color: var(--msg-success); }
        .stat-card.delivered .stat-value { color: var(--msg-success); }
        
        .stat-label {
            font-size: 0.75rem;
            color: var(--msg-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        
        /* Filter Section */
        .filter-section {
            background: white;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        
        .filter-section .form-select,
        .filter-section .btn {
            border-radius: 10px;
        }
        
        /* Message Cards (Mobile First) */
        .message-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        
        .message-card {
            background: white;
            border-radius: 12px;
            padding: 1rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            border-left: 4px solid transparent;
            transition: all 0.2s;
        }
        
        .message-card:hover {
            box-shadow: 0 4px 16px rgba(0,0,0,0.1);
        }
        
        .message-card.sms {
            border-left-color: var(--msg-info);
        }
        
        .message-card.whatsapp {
            border-left-color: var(--msg-success);
        }
        
        .message-card.failed {
            border-left-color: var(--msg-danger);
            background: #fff5f5;
        }
        
        .message-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.75rem;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        
        .message-time {
            font-size: 0.8125rem;
            color: var(--msg-secondary);
        }
        
        .message-badges {
            display: flex;
            gap: 0.375rem;
            flex-wrap: wrap;
        }
        
        .channel-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.625rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .channel-badge.sms {
            background: rgba(13, 202, 240, 0.15);
            color: #0aa2c0;
        }
        
        .channel-badge.whatsapp {
            background: rgba(37, 211, 102, 0.15);
            color: #128C7E;
        }
        
        .status-badge {
            padding: 0.25rem 0.625rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .status-badge.sent { background: rgba(13, 110, 253, 0.1); color: var(--msg-primary); }
        .status-badge.delivered { background: rgba(25, 135, 84, 0.1); color: var(--msg-success); }
        .status-badge.read { background: rgba(25, 135, 84, 0.15); color: var(--msg-success); }
        .status-badge.failed { background: rgba(220, 53, 69, 0.1); color: var(--msg-danger); }
        .status-badge.pending { background: rgba(255, 193, 7, 0.15); color: #997404; }
        
        .message-content {
            background: var(--msg-light);
            padding: 0.875rem;
            border-radius: 10px;
            font-size: 0.9375rem;
            line-height: 1.5;
            color: var(--msg-dark);
            word-wrap: break-word;
            margin-bottom: 0.75rem;
        }
        
        .message-footer {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            font-size: 0.8125rem;
            color: var(--msg-secondary);
        }
        
        .message-footer-item {
            display: flex;
            align-items: center;
            gap: 0.375rem;
        }
        
        .message-footer-item i {
            font-size: 0.75rem;
        }
        
        /* Search Results */
        .search-results {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.75rem;
        }
        
        .search-result-card {
            background: white;
            border-radius: 12px;
            padding: 1rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            display: flex;
            align-items: center;
            gap: 1rem;
            text-decoration: none;
            color: inherit;
            transition: all 0.2s;
            border: 2px solid transparent;
        }
        
        .search-result-card:hover {
            border-color: var(--msg-primary);
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(0,0,0,0.1);
            color: inherit;
        }
        
        .result-avatar {
            width: 48px;
            height: 48px;
            min-width: 48px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--msg-primary) 0%, #0a58ca 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.125rem;
        }
        
        .result-info {
            flex: 1;
            min-width: 0;
        }
        
        .result-name {
            font-weight: 600;
            color: var(--msg-dark);
            margin-bottom: 0.25rem;
        }
        
        .result-meta {
            font-size: 0.8125rem;
            color: var(--msg-secondary);
        }
        
        .result-arrow {
            color: var(--msg-secondary);
            opacity: 0.5;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1.5rem;
            background: white;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        }
        
        .empty-state-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: var(--msg-light);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
        }
        
        .empty-state-icon i {
            font-size: 2rem;
            color: var(--msg-secondary);
        }
        
        .empty-state h5 {
            font-weight: 700;
            color: var(--msg-dark);
            margin-bottom: 0.5rem;
        }
        
        .empty-state p {
            color: var(--msg-secondary);
            margin: 0;
        }
        
        /* View Profile Button */
        .btn-view-profile {
            background: linear-gradient(135deg, var(--msg-primary) 0%, #0a58ca 100%);
            color: white;
            border: none;
            border-radius: 10px;
            padding: 0.5rem 1rem;
            font-size: 0.8125rem;
            font-weight: 600;
            transition: all 0.2s;
        }
        
        .btn-view-profile:hover {
            color: white;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(13, 110, 253, 0.3);
        }
        
        /* Desktop Table */
        .desktop-table {
            display: none;
        }
        
        /* Responsive Adjustments */
        @media (min-width: 576px) {
            .search-results {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (min-width: 768px) {
            .page-header {
                padding: 2rem;
            }
            
            .page-header h2 {
                font-size: 1.75rem;
            }
            
            .stats-grid {
                gap: 1rem;
            }
            
            .stat-card {
                padding: 1.25rem;
            }
            
            .stat-value {
                font-size: 1.75rem;
            }
            
            .donor-avatar {
                width: 64px;
                height: 64px;
                min-width: 64px;
                font-size: 1.75rem;
            }
            
            .desktop-table {
                display: block;
            }
            
            .message-list {
                display: none;
            }
            
            .search-results {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        
        @media (max-width: 575px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .stat-icon {
                width: 36px;
                height: 36px;
                font-size: 0.875rem;
            }
            
            .stat-value {
                font-size: 1.25rem;
            }
            
            .donor-profile {
                flex-direction: column;
                text-align: center;
            }
            
            .donor-meta {
                justify-content: center;
            }
            
            .page-header {
                text-align: center;
            }
            
            .page-header .btn-back {
                margin-top: 1rem;
            }
            
            .filter-section .row {
                gap: 0.75rem;
            }
            
            .filter-section .btn {
                width: 100%;
            }
        }
        
        /* Table Styling for Desktop */
        .table-wrapper {
            background: white;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        
        .table-wrapper .table {
            margin: 0;
        }
        
        .table-wrapper .table th {
            background: var(--msg-light);
            font-weight: 600;
            font-size: 0.8125rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--msg-secondary);
            padding: 1rem;
            border: none;
        }
        
        .table-wrapper .table td {
            padding: 1rem;
            vertical-align: middle;
            border-color: #f0f0f0;
        }
        
        .table-wrapper .table tbody tr {
            transition: background 0.2s;
        }
        
        .table-wrapper .table tbody tr:hover {
            background: rgba(13, 110, 253, 0.02);
        }
        
        .table-wrapper .table tbody tr.sms {
            border-left: 4px solid var(--msg-info);
        }
        
        .table-wrapper .table tbody tr.whatsapp {
            border-left: 4px solid var(--msg-success);
        }
        
        .table-wrapper .table tbody tr.failed {
            background: #fff8f8;
            border-left: 4px solid var(--msg-danger);
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="container-fluid p-3 p-md-4">
                
                <!-- Page Header -->
                <div class="page-header d-flex flex-column flex-md-row justify-content-between align-items-center">
                    <div class="text-center text-md-start">
                        <h2><i class="fas fa-envelope me-2"></i>Message History</h2>
                        <p>Search for donors and view their communication history</p>
                    </div>
                    <a href="donors.php" class="btn btn-back mt-3 mt-md-0">
                        <i class="fas fa-arrow-left me-2"></i>Back to Donors
                    </a>
                </div>
                
                <!-- Search Section -->
                <div class="search-section">
                    <form method="GET" action="">
                        <div class="row g-3">
                            <div class="col-12 col-md-9">
                                <div class="position-relative">
                                    <input 
                                        type="text" 
                                        class="form-control search-input" 
                                        name="search" 
                                        value="<?= htmlspecialchars($search_term) ?>" 
                                        placeholder="Search by name, phone, or reference #..."
                                        autofocus
                                    >
                                </div>
                            </div>
                            <div class="col-12 col-md-3">
                                <button type="submit" class="btn btn-primary search-btn w-100">
                                    <i class="fas fa-search me-2"></i>Search
                                </button>
                            </div>
                        </div>
                        <?php if ($donorId): ?>
                            <input type="hidden" name="donor_id" value="<?= $donorId ?>">
                        <?php endif; ?>
                    </form>
                </div>
                
                <!-- Search Results -->
                <?php if (!empty($search_term) && empty($donorId) && !empty($search_results)): ?>
                    <div class="mb-4">
                        <h5 class="mb-3 fw-bold">
                            <i class="fas fa-users me-2 text-primary"></i>
                            Found <?= count($search_results) ?> donor<?= count($search_results) !== 1 ? 's' : '' ?>
                        </h5>
                        <div class="search-results">
                            <?php foreach ($search_results as $result): ?>
                                <a href="?donor_id=<?= $result['id'] ?>&search=<?= urlencode($search_term) ?>" 
                                   class="search-result-card">
                                    <div class="result-avatar">
                                        <?= strtoupper(substr($result['name'], 0, 1)) ?>
                                    </div>
                                    <div class="result-info">
                                        <div class="result-name"><?= htmlspecialchars($result['name']) ?></div>
                                        <div class="result-meta">
                                            <i class="fas fa-phone me-1"></i><?= htmlspecialchars($result['phone']) ?>
                                            <span class="mx-2">•</span>
                                            <i class="fas fa-hashtag me-1"></i><?= htmlspecialchars($result['reference_number']) ?>
                                        </div>
                                    </div>
                                    <i class="fas fa-chevron-right result-arrow"></i>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php elseif (!empty($search_term) && empty($donorId) && empty($search_results)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-search"></i>
                        </div>
                        <h5>No donors found</h5>
                        <p>Try a different search term</p>
                    </div>
                <?php endif; ?>
                
                <!-- Donor Message History -->
                <?php if ($donorId && $donor): ?>
                    <!-- Donor Profile Card -->
                    <div class="donor-profile d-flex flex-column flex-md-row align-items-center gap-3">
                        <div class="donor-avatar">
                            <?= strtoupper(substr($donor['name'], 0, 1)) ?>
                        </div>
                        <div class="flex-grow-1 text-center text-md-start">
                            <h4 class="donor-name"><?= htmlspecialchars($donor['name']) ?></h4>
                            <div class="donor-meta">
                                <span class="donor-meta-item">
                                    <i class="fas fa-phone"></i>
                                    <?= htmlspecialchars($donor['phone']) ?>
                                </span>
                                <span class="donor-meta-item">
                                    <i class="fas fa-hashtag"></i>
                                    Ref: <?= htmlspecialchars($donor_reference) ?>
                                </span>
                            </div>
                        </div>
                        <a href="view-donor.php?id=<?= $donorId ?>" class="btn btn-view-profile">
                            <i class="fas fa-user me-1"></i>View Profile
                        </a>
                    </div>
                    
                    <!-- Statistics Grid -->
                    <div class="stats-grid">
                        <div class="stat-card total">
                            <div class="stat-icon">
                                <i class="fas fa-envelope"></i>
                            </div>
                            <div class="stat-value"><?= number_format($stats['total_messages'] ?? 0) ?></div>
                            <div class="stat-label">Total</div>
                        </div>
                        <div class="stat-card sms">
                            <div class="stat-icon">
                                <i class="fas fa-sms"></i>
                            </div>
                            <div class="stat-value"><?= number_format($stats['sms_count'] ?? 0) ?></div>
                            <div class="stat-label">SMS</div>
                        </div>
                        <div class="stat-card whatsapp">
                            <div class="stat-icon">
                                <i class="fab fa-whatsapp"></i>
                            </div>
                            <div class="stat-value"><?= number_format($stats['whatsapp_count'] ?? 0) ?></div>
                            <div class="stat-label">WhatsApp</div>
                        </div>
                        <div class="stat-card delivered">
                            <div class="stat-icon">
                                <i class="fas fa-check-double"></i>
                            </div>
                            <div class="stat-value"><?= number_format($stats['delivered_count'] ?? 0) ?></div>
                            <div class="stat-label">Delivered</div>
                        </div>
                    </div>
                    
                    <!-- Filter Section -->
                    <div class="filter-section">
                        <form method="GET" class="row g-2 g-md-3 align-items-end">
                            <input type="hidden" name="donor_id" value="<?= $donorId ?>">
                            <?php if (!empty($search_term)): ?>
                                <input type="hidden" name="search" value="<?= htmlspecialchars($search_term) ?>">
                            <?php endif; ?>
                            <div class="col-6 col-md-4">
                                <label class="form-label small fw-bold text-muted">Channel</label>
                                <select name="channel" class="form-select">
                                    <option value="">All</option>
                                    <option value="sms" <?= ($_GET['channel'] ?? '') === 'sms' ? 'selected' : '' ?>>SMS</option>
                                    <option value="whatsapp" <?= ($_GET['channel'] ?? '') === 'whatsapp' ? 'selected' : '' ?>>WhatsApp</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-4">
                                <label class="form-label small fw-bold text-muted">Show</label>
                                <select name="limit" class="form-select">
                                    <option value="25" <?= ($_GET['limit'] ?? 50) == 25 ? 'selected' : '' ?>>25</option>
                                    <option value="50" <?= ($_GET['limit'] ?? 50) == 50 ? 'selected' : '' ?>>50</option>
                                    <option value="100" <?= ($_GET['limit'] ?? 50) == 100 ? 'selected' : '' ?>>100</option>
                                </select>
                            </div>
                            <div class="col-12 col-md-4 d-flex gap-2">
                                <button type="submit" class="btn btn-primary flex-grow-1">
                                    <i class="fas fa-filter me-1"></i>Filter
                                </button>
                                <a href="?donor_id=<?= $donorId ?>" class="btn btn-outline-secondary">
                                    <i class="fas fa-times"></i>
                                </a>
                            </div>
                        </form>
                    </div>
                    
                    <?php if (empty($messages)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <i class="fas fa-inbox"></i>
                            </div>
                            <h5>No messages found</h5>
                            <p>This donor hasn't received any messages yet</p>
                        </div>
                    <?php else: ?>
                        <!-- Mobile Message Cards -->
                        <div class="message-list">
                            <?php foreach ($messages as $message): ?>
                                <?php
                                $cardClass = 'message-card';
                                if ($message['channel'] === 'sms') $cardClass .= ' sms';
                                if ($message['channel'] === 'whatsapp') $cardClass .= ' whatsapp';
                                if ($message['status'] === 'failed') $cardClass .= ' failed';
                                
                                $status = $message['status'] ?? 'sent';
                                ?>
                                <div class="<?= $cardClass ?>">
                                    <div class="message-header">
                                        <span class="message-time">
                                            <i class="far fa-clock me-1"></i>
                                            <?= date('d M Y, H:i', strtotime($message['sent_at'])) ?>
                                        </span>
                                        <div class="message-badges">
                                            <?php if ($message['channel'] === 'sms'): ?>
                                                <span class="channel-badge sms">
                                                    <i class="fas fa-sms"></i>SMS
                                                </span>
                                            <?php elseif ($message['channel'] === 'whatsapp'): ?>
                                                <span class="channel-badge whatsapp">
                                                    <i class="fab fa-whatsapp"></i>WhatsApp
                                                </span>
                                            <?php endif; ?>
                                            <span class="status-badge <?= $status ?>">
                                                <?= ucfirst($status) ?>
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <div class="message-content">
                                        <?= nl2br(htmlspecialchars($message['message_content'] ?? '')) ?>
                                    </div>
                                    
                                    <div class="message-footer">
                                        <div class="message-footer-item">
                                            <i class="fas fa-user"></i>
                                            <?= $message['sent_by_name'] ? htmlspecialchars($message['sent_by_name']) : 'System' ?>
                                        </div>
                                        <?php if ($message['template_key']): ?>
                                            <div class="message-footer-item">
                                                <i class="fas fa-file-alt"></i>
                                                <?= htmlspecialchars($message['template_key']) ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($message['cost_pence']): ?>
                                            <div class="message-footer-item">
                                                <i class="fas fa-pound-sign"></i>
                                                £<?= number_format($message['cost_pence'] / 100, 2) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- Desktop Table -->
                        <div class="desktop-table">
                            <div class="table-wrapper">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Date & Time</th>
                                            <th>Channel</th>
                                            <th>Message</th>
                                            <th>Template</th>
                                            <th>Sent By</th>
                                            <th>Status</th>
                                            <th>Cost</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($messages as $message): ?>
                                            <?php
                                            $rowClass = '';
                                            if ($message['channel'] === 'sms') $rowClass = 'sms';
                                            if ($message['channel'] === 'whatsapp') $rowClass = 'whatsapp';
                                            if ($message['status'] === 'failed') $rowClass = 'failed';
                                            
                                            $status = $message['status'] ?? 'sent';
                                            ?>
                                            <tr class="<?= $rowClass ?>">
                                                <td>
                                                    <div class="fw-medium"><?= date('d M Y', strtotime($message['sent_at'])) ?></div>
                                                    <small class="text-muted"><?= date('H:i', strtotime($message['sent_at'])) ?></small>
                                                </td>
                                                <td>
                                                    <?php if ($message['channel'] === 'sms'): ?>
                                                        <span class="channel-badge sms">
                                                            <i class="fas fa-sms"></i>SMS
                                                        </span>
                                                    <?php elseif ($message['channel'] === 'whatsapp'): ?>
                                                        <span class="channel-badge whatsapp">
                                                            <i class="fab fa-whatsapp"></i>WhatsApp
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="max-width: 300px;">
                                                    <div class="text-truncate" title="<?= htmlspecialchars($message['message_content'] ?? '') ?>">
                                                        <?= htmlspecialchars(mb_substr($message['message_content'] ?? '', 0, 80)) ?>
                                                        <?= mb_strlen($message['message_content'] ?? '') > 80 ? '...' : '' ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if ($message['template_key']): ?>
                                                        <code class="small bg-light px-2 py-1 rounded"><?= htmlspecialchars($message['template_key']) ?></code>
                                                    <?php else: ?>
                                                        <span class="text-muted small">Direct</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?= $message['sent_by_name'] ? htmlspecialchars($message['sent_by_name']) : '<span class="text-muted">System</span>' ?>
                                                </td>
                                                <td>
                                                    <span class="status-badge <?= $status ?>">
                                                        <?= ucfirst($status) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($message['cost_pence']): ?>
                                                        £<?= number_format($message['cost_pence'] / 100, 2) ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
// Fallback for sidebar toggle (same as donors.php)
if (typeof window.toggleSidebar !== 'function') {
  window.toggleSidebar = function() {
    var body = document.body;
    var sidebar = document.getElementById('sidebar');
    var overlay = document.querySelector('.sidebar-overlay');
    if (window.innerWidth <= 991.98) {
      if (sidebar) sidebar.classList.toggle('active');
      if (overlay) overlay.classList.toggle('active');
      body.style.overflow = (sidebar && sidebar.classList.contains('active')) ? 'hidden' : '';
    } else {
      body.classList.toggle('sidebar-collapsed');
    }
  };
}
</script>
</body>
</html>
