<?php
declare(strict_types=1);

/**
 * API v1 - Donor Payments Endpoint
 * 
 * GET /api/v1/donor/payments - Get donor's payment history
 * POST /api/v1/donor/payments - Record a new payment (pending approval)
 */

require_once __DIR__ . '/../ApiResponse.php';
require_once __DIR__ . '/../ApiAuth.php';
require_once __DIR__ . '/../../../config/db.php';

// Handle CORS preflight
ApiResponse::handlePreflight();

// Require donor authentication
$auth = new ApiAuth();
$authData = $auth->requireRole('donor');

$db = db();
$donorId = $authData['user_id'];

// Get donor info
$donorStmt = $db->prepare("SELECT phone, name FROM donors WHERE id = ? LIMIT 1");
$donorStmt->bind_param('i', $donorId);
$donorStmt->execute();
$donor = $donorStmt->get_result()->fetch_assoc();
$donorStmt->close();

if (!$donor) {
    ApiResponse::error('Donor not found', 404, 'DONOR_NOT_FOUND');
}

$phone = $donor['phone'];
$name = $donor['name'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Pagination
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = min(50, max(1, (int) ($_GET['per_page'] ?? 20)));
    $offset = ($page - 1) * $perPage;

    // Get total count
    $countStmt = $db->prepare(
        "SELECT COUNT(*) as total FROM payments WHERE donor_phone = ?"
    );
    $countStmt->bind_param('s', $phone);
    $countStmt->execute();
    $total = (int) $countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    // Get payments
    $stmt = $db->prepare(
        "SELECT id, amount, currency, payment_method, payment_date, status, 
                reference_number, notes, created_at
         FROM payments 
         WHERE donor_phone = ?
         ORDER BY payment_date DESC, created_at DESC
         LIMIT ? OFFSET ?"
    );
    $stmt->bind_param('sii', $phone, $perPage, $offset);
    $stmt->execute();
    $result = $stmt->get_result();

    $payments = [];
    while ($row = $result->fetch_assoc()) {
        $payments[] = [
            'id' => (int) $row['id'],
            'amount' => (float) $row['amount'],
            'currency' => $row['currency'] ?? 'GBP',
            'payment_method' => $row['payment_method'],
            'payment_date' => $row['payment_date'],
            'status' => $row['status'],
            'reference_number' => $row['reference_number'],
            'notes' => $row['notes'],
            'created_at' => $row['created_at'],
        ];
    }
    $stmt->close();

    ApiResponse::paginated($payments, $page, $perPage, $total);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Record a new payment
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        ApiResponse::error('Invalid JSON body', 400, 'INVALID_JSON');
    }

    // Validate required fields
    $amount = $input['amount'] ?? null;
    $paymentMethod = $input['payment_method'] ?? null;
    $paymentDate = $input['payment_date'] ?? date('Y-m-d');
    $referenceNumber = $input['reference_number'] ?? null;
    $notes = $input['notes'] ?? null;

    if (!$amount || !is_numeric($amount) || (float) $amount <= 0) {
        ApiResponse::error('Valid amount is required', 422, 'VALIDATION_ERROR', [
            'amount' => 'Amount must be a positive number',
        ]);
    }

    $validMethods = ['cash', 'card', 'bank_transfer', 'other'];
    if (!$paymentMethod || !in_array($paymentMethod, $validMethods, true)) {
        ApiResponse::error('Valid payment method is required', 422, 'VALIDATION_ERROR', [
            'payment_method' => 'Must be one of: ' . implode(', ', $validMethods),
        ]);
    }

    // Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate)) {
        ApiResponse::error('Invalid date format', 422, 'VALIDATION_ERROR', [
            'payment_date' => 'Date must be in YYYY-MM-DD format',
        ]);
    }

    // Insert payment with pending status
    $stmt = $db->prepare(
        "INSERT INTO payments 
         (donor_name, donor_phone, amount, currency, payment_method, payment_date, 
          reference_number, notes, status, source)
         VALUES (?, ?, ?, 'GBP', ?, ?, ?, ?, 'pending', 'pwa_donor')"
    );
    $stmt->bind_param(
        'ssdssss',
        $name,
        $phone,
        $amount,
        $paymentMethod,
        $paymentDate,
        $referenceNumber,
        $notes
    );
    $stmt->execute();
    $paymentId = $stmt->insert_id;
    $stmt->close();

    ApiResponse::success([
        'payment_id' => $paymentId,
        'status' => 'pending',
        'message' => 'Payment recorded and pending approval',
    ], 'Payment submitted successfully');
} else {
    ApiResponse::error('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
}

