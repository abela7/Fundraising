<?php
declare(strict_types=1);

/**
 * API v1 - Registrar Registrations Endpoint
 * 
 * GET /api/v1/registrar/registrations - Get registrar's registrations
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

// Require registrar or admin authentication
$auth = new ApiAuth();
$authData = $auth->requireRole(['registrar', 'admin']);

$db = db();
$userId = $authData['user_id'];

// Pagination
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 20)));
$offset = ($page - 1) * $perPage;

// Filter by status
$status = $_GET['status'] ?? null;

// Build query
$whereClause = "registered_by = ?";
$params = [$userId];
$types = 'i';

if ($status && in_array($status, ['pending', 'approved', 'rejected'], true)) {
    $whereClause .= " AND status = ?";
    $params[] = $status;
    $types .= 's';
}

// Get total count
$countSql = "SELECT COUNT(*) as total FROM pledges WHERE {$whereClause}";
$countStmt = $db->prepare($countSql);
$countStmt->bind_param($types, ...$params);
$countStmt->execute();
$total = (int) $countStmt->get_result()->fetch_assoc()['total'];
$countStmt->close();

// Get registrations
$params[] = $perPage;
$params[] = $offset;
$types .= 'ii';

$sql = "SELECT id, donor_name, donor_phone, amount, currency, pledge_date, status, 
               notes, created_at
        FROM pledges 
        WHERE {$whereClause}
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?";
$stmt = $db->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$registrations = [];
while ($row = $result->fetch_assoc()) {
    $registrations[] = [
        'id' => (int) $row['id'],
        'donor_name' => $row['donor_name'],
        'donor_phone' => $row['donor_phone'],
        'amount' => (float) $row['amount'],
        'currency' => $row['currency'] ?? 'GBP',
        'pledge_date' => $row['pledge_date'],
        'status' => $row['status'],
        'notes' => $row['notes'],
        'created_at' => $row['created_at'],
    ];
}
$stmt->close();

ApiResponse::paginated($registrations, $page, $perPage, $total);

