<?php
declare(strict_types=1);

/**
 * API v1 - Donor Profile Endpoint
 * 
 * GET /api/v1/donor/profile - Get current donor profile
 * PUT /api/v1/donor/profile - Update donor profile
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

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Get full donor profile
    $stmt = $db->prepare(
        "SELECT id, name, phone, total_pledged, total_paid, balance,
                has_active_plan, active_payment_plan_id, payment_status,
                preferred_payment_method, preferred_language,
                created_at, last_login_at, login_count
         FROM donors WHERE id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $donorId);
    $stmt->execute();
    $donor = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$donor) {
        ApiResponse::error('Donor not found', 404, 'DONOR_NOT_FOUND');
    }

    // Get active payment plan details if exists
    $paymentPlan = null;
    if ($donor['active_payment_plan_id']) {
        $planStmt = $db->prepare(
            "SELECT id, frequency, installment_amount, next_payment_date, 
                    total_installments, completed_installments, status
             FROM payment_plans WHERE id = ? LIMIT 1"
        );
        $planStmt->bind_param('i', $donor['active_payment_plan_id']);
        $planStmt->execute();
        $paymentPlan = $planStmt->get_result()->fetch_assoc();
        $planStmt->close();
    }

    ApiResponse::success([
        'id' => (int) $donor['id'],
        'name' => $donor['name'],
        'phone' => $donor['phone'],
        'total_pledged' => (float) $donor['total_pledged'],
        'total_paid' => (float) $donor['total_paid'],
        'balance' => (float) $donor['balance'],
        'has_active_plan' => (bool) $donor['has_active_plan'],
        'payment_status' => $donor['payment_status'],
        'preferred_payment_method' => $donor['preferred_payment_method'],
        'preferred_language' => $donor['preferred_language'],
        'payment_plan' => $paymentPlan ? [
            'id' => (int) $paymentPlan['id'],
            'frequency' => $paymentPlan['frequency'],
            'installment_amount' => (float) $paymentPlan['installment_amount'],
            'next_payment_date' => $paymentPlan['next_payment_date'],
            'total_installments' => (int) $paymentPlan['total_installments'],
            'completed_installments' => (int) $paymentPlan['completed_installments'],
            'status' => $paymentPlan['status'],
        ] : null,
        'member_since' => $donor['created_at'],
        'last_login' => $donor['last_login_at'],
    ]);
} elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    // Update donor profile
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        ApiResponse::error('Invalid JSON body', 400, 'INVALID_JSON');
    }

    $updates = [];
    $params = [];
    $types = '';

    // Only allow updating certain fields
    if (isset($input['name'])) {
        $name = trim($input['name']);
        if (strlen($name) < 2 || strlen($name) > 100) {
            ApiResponse::error('Name must be between 2 and 100 characters', 422, 'VALIDATION_ERROR', [
                'name' => 'Invalid name length',
            ]);
        }
        $updates[] = 'name = ?';
        $params[] = $name;
        $types .= 's';
    }

    if (isset($input['preferred_payment_method'])) {
        $method = $input['preferred_payment_method'];
        $validMethods = ['cash', 'card', 'bank_transfer', 'other'];
        if (!in_array($method, $validMethods, true)) {
            ApiResponse::error('Invalid payment method', 422, 'VALIDATION_ERROR', [
                'preferred_payment_method' => 'Must be one of: ' . implode(', ', $validMethods),
            ]);
        }
        $updates[] = 'preferred_payment_method = ?';
        $params[] = $method;
        $types .= 's';
    }

    if (isset($input['preferred_language'])) {
        $lang = $input['preferred_language'];
        $validLangs = ['en', 'am', 'ti'];
        if (!in_array($lang, $validLangs, true)) {
            ApiResponse::error('Invalid language', 422, 'VALIDATION_ERROR', [
                'preferred_language' => 'Must be one of: ' . implode(', ', $validLangs),
            ]);
        }
        $updates[] = 'preferred_language = ?';
        $params[] = $lang;
        $types .= 's';
    }

    if (empty($updates)) {
        ApiResponse::error('No valid fields to update', 400, 'NO_UPDATES');
    }

    // Add donor ID to params
    $params[] = $donorId;
    $types .= 'i';

    $sql = "UPDATE donors SET " . implode(', ', $updates) . " WHERE id = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->close();

    ApiResponse::success(null, 'Profile updated successfully');
} else {
    ApiResponse::error('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
}

