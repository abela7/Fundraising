<?php
declare(strict_types=1);

/**
 * API v1 - Donor Pledges Endpoint
 * 
 * GET /api/v1/donor/pledges - Get donor's pledges
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

// Require donor authentication
$auth = new ApiAuth();
$authData = $auth->requireRole('donor');

$db = db();
$donorId = $authData['user_id'];

// Get donor phone for pledge lookup
$donorStmt = $db->prepare("SELECT phone FROM donors WHERE id = ? LIMIT 1");
$donorStmt->bind_param('i', $donorId);
$donorStmt->execute();
$donor = $donorStmt->get_result()->fetch_assoc();
$donorStmt->close();

if (!$donor) {
    ApiResponse::error('Donor not found', 404, 'DONOR_NOT_FOUND');
}

$phone = $donor['phone'];

// Pagination
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(50, max(1, (int) ($_GET['per_page'] ?? 20)));
$offset = ($page - 1) * $perPage;

// Get total count
$countStmt = $db->prepare(
    "SELECT COUNT(*) as total FROM pledges WHERE donor_phone = ?"
);
$countStmt->bind_param('s', $phone);
$countStmt->execute();
$total = (int) $countStmt->get_result()->fetch_assoc()['total'];
$countStmt->close();

// Get pledges
$stmt = $db->prepare(
    "SELECT id, amount, currency, pledge_date, status, notes, created_at
     FROM pledges 
     WHERE donor_phone = ?
     ORDER BY pledge_date DESC, created_at DESC
     LIMIT ? OFFSET ?"
);
$stmt->bind_param('sii', $phone, $perPage, $offset);
$stmt->execute();
$result = $stmt->get_result();

$pledges = [];
while ($row = $result->fetch_assoc()) {
    $pledges[] = [
        'id' => (int) $row['id'],
        'amount' => (float) $row['amount'],
        'currency' => $row['currency'] ?? 'GBP',
        'pledge_date' => $row['pledge_date'],
        'status' => $row['status'],
        'notes' => $row['notes'],
        'created_at' => $row['created_at'],
    ];
}
$stmt->close();

ApiResponse::paginated($pledges, $page, $perPage, $total);

