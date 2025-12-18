<?php
declare(strict_types=1);

/**
 * API v1 - Admin Donors Endpoint
 * 
 * GET /api/v1/admin/donors - List all donors with filters
 * GET /api/v1/admin/donors/{id} - Get single donor details
 */

require_once __DIR__ . '/../ApiResponse.php';
require_once __DIR__ . '/../ApiAuth.php';
require_once __DIR__ . '/../../../config/db.php';

// Handle CORS preflight
ApiResponse::handlePreflight();

// Only accept GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ApiResponse::error('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
}

// Require admin authentication
$auth = new ApiAuth();
$authData = $auth->requireRole('admin');

$db = db();

// Check if requesting single donor
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
if (preg_match('/^\/(\d+)$/', $pathInfo, $matches)) {
    // Single donor
    $donorId = (int) $matches[1];
    
    $stmt = $db->prepare(
        "SELECT id, name, phone, total_pledged, total_paid, balance,
                has_active_plan, active_payment_plan_id, payment_status,
                preferred_payment_method, preferred_language,
                source, created_at, last_login_at, login_count
         FROM donors WHERE id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $donorId);
    $stmt->execute();
    $donor = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$donor) {
        ApiResponse::error('Donor not found', 404, 'DONOR_NOT_FOUND');
    }
    
    // Get recent pledges
    $pledgesStmt = $db->prepare(
        "SELECT id, amount, status, pledge_date, created_at
         FROM pledges WHERE donor_phone = ? ORDER BY created_at DESC LIMIT 5"
    );
    $pledgesStmt->bind_param('s', $donor['phone']);
    $pledgesStmt->execute();
    $pledgesResult = $pledgesStmt->get_result();
    $pledges = [];
    while ($row = $pledgesResult->fetch_assoc()) {
        $pledges[] = $row;
    }
    $pledgesStmt->close();
    
    // Get recent payments
    $paymentsStmt = $db->prepare(
        "SELECT id, amount, status, payment_date, payment_method, created_at
         FROM payments WHERE donor_phone = ? ORDER BY created_at DESC LIMIT 5"
    );
    $paymentsStmt->bind_param('s', $donor['phone']);
    $paymentsStmt->execute();
    $paymentsResult = $paymentsStmt->get_result();
    $payments = [];
    while ($row = $paymentsResult->fetch_assoc()) {
        $payments[] = $row;
    }
    $paymentsStmt->close();
    
    ApiResponse::success([
        'donor' => [
            'id' => (int) $donor['id'],
            'name' => $donor['name'],
            'phone' => $donor['phone'],
            'total_pledged' => (float) $donor['total_pledged'],
            'total_paid' => (float) $donor['total_paid'],
            'balance' => (float) $donor['balance'],
            'has_active_plan' => (bool) $donor['has_active_plan'],
            'payment_status' => $donor['payment_status'],
            'source' => $donor['source'],
            'created_at' => $donor['created_at'],
            'last_login_at' => $donor['last_login_at'],
            'login_count' => (int) ($donor['login_count'] ?? 0),
        ],
        'recent_pledges' => $pledges,
        'recent_payments' => $payments,
    ]);
}

// List donors with pagination and filters
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 20)));
$offset = ($page - 1) * $perPage;

$search = $_GET['search'] ?? null;
$status = $_GET['status'] ?? null;
$sortBy = $_GET['sort_by'] ?? 'created_at';
$sortOrder = strtoupper($_GET['sort_order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

// Build query
$whereConditions = [];
$params = [];
$types = '';

if ($search) {
    $searchTerm = '%' . $search . '%';
    $whereConditions[] = "(name LIKE ? OR phone LIKE ?)";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= 'ss';
}

if ($status && in_array($status, ['paid', 'partial', 'unpaid', 'overpaid'], true)) {
    $whereConditions[] = "payment_status = ?";
    $params[] = $status;
    $types .= 's';
}

$whereClause = $whereConditions ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Validate sort column
$allowedSorts = ['created_at', 'name', 'total_pledged', 'total_paid', 'balance'];
if (!in_array($sortBy, $allowedSorts, true)) {
    $sortBy = 'created_at';
}

// Get total count
$countSql = "SELECT COUNT(*) as total FROM donors {$whereClause}";
$countStmt = $db->prepare($countSql);
if ($params) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$total = (int) $countStmt->get_result()->fetch_assoc()['total'];
$countStmt->close();

// Get donors
$params[] = $perPage;
$params[] = $offset;
$types .= 'ii';

$sql = "SELECT id, name, phone, total_pledged, total_paid, balance, payment_status, created_at
        FROM donors 
        {$whereClause}
        ORDER BY {$sortBy} {$sortOrder}
        LIMIT ? OFFSET ?";
$stmt = $db->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$donors = [];
while ($row = $result->fetch_assoc()) {
    $donors[] = [
        'id' => (int) $row['id'],
        'name' => $row['name'],
        'phone' => $row['phone'],
        'total_pledged' => (float) $row['total_pledged'],
        'total_paid' => (float) $row['total_paid'],
        'balance' => (float) $row['balance'],
        'payment_status' => $row['payment_status'],
        'created_at' => $row['created_at'],
    ];
}
$stmt->close();

ApiResponse::paginated($donors, $page, $perPage, $total);

