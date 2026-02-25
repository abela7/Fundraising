<?php
/**
 * API: Get Donor Certificate Data
 *
 * Returns all data needed to render a certificate for a donor,
 * including pledge/payment totals, area allocation, and progress.
 */

declare(strict_types=1);

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../../../shared/auth.php';
    require_once __DIR__ . '/../../../config/db.php';
    require_login();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$donorId = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0;

if ($donorId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid donor ID'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

try {
    $db = db();

    // Get donor basic info + totals
    $stmt = $db->prepare("
        SELECT d.id, d.name, d.phone, d.total_pledged, d.total_paid
        FROM donors d
        WHERE d.id = ?
    ");
    $stmt->bind_param('i', $donorId);
    $stmt->execute();
    $donor = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$donor) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Donor not found'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    // Get reference number from most recent pledge notes
    $reference = '';
    $refStmt = $db->prepare("
        SELECT notes FROM pledges WHERE donor_id = ? ORDER BY id DESC LIMIT 1
    ");
    $refStmt->bind_param('i', $donorId);
    $refStmt->execute();
    $pledgeRow = $refStmt->get_result()->fetch_assoc();
    $refStmt->close();

    if ($pledgeRow && preg_match('/\b(\d{4})\b/', $pledgeRow['notes'] ?? '', $matches)) {
        $reference = $matches[1];
    }
    if (!$reference) {
        $reference = str_pad((string)$donorId, 4, '0', STR_PAD_LEFT);
    }

    // Calculate certificate values
    $totalPledged = (float)($donor['total_pledged'] ?? 0);
    $totalPaid = (float)($donor['total_paid'] ?? 0);
    $allocationBase = max($totalPledged, $totalPaid);
    $sqmValue = round($allocationBase / 400, 2);
    $paymentProgress = $totalPledged > 0
        ? min(100, round(($totalPaid / $totalPledged) * 100))
        : ($totalPaid > 0 ? 100 : 0);
    $isFullyPaid = $totalPledged > 0 && $totalPaid >= $totalPledged;
    $hasPledge = $totalPledged > 0 || $totalPaid > 0;

    echo json_encode([
        'success' => true,
        'donor' => [
            'id' => (int)$donor['id'],
            'name' => $donor['name'],
            'phone' => $donor['phone'],
            'reference' => $reference,
            'total_pledged' => $totalPledged,
            'total_paid' => $totalPaid,
            'allocation_base' => $allocationBase,
            'sqm_value' => $sqmValue,
            'payment_progress' => $paymentProgress,
            'is_fully_paid' => $isFullyPaid,
            'has_pledge' => $hasPledge,
            'currency' => '£'
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

} catch (Exception $e) {
    error_log("Get donor certificate data error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}
