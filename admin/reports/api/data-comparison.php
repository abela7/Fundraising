<?php
declare(strict_types=1);

require_once '../../../config/db.php';
require_once '../../../shared/auth.php';

header('Content-Type: application/json');

require_login();
require_admin();

try {
    $db = db();

    $hasDataSource = false;
    $dsCheck = $db->query("SHOW COLUMNS FROM donors LIKE 'data_source'");
    if ($dsCheck && $dsCheck->num_rows > 0) {
        $hasDataSource = true;
    }

    $hasCity = false;
    $cityCheck = $db->query("SHOW COLUMNS FROM donors LIKE 'city'");
    if ($cityCheck && $cityCheck->num_rows > 0) {
        $hasCity = true;
    }

    $hasPaymentMethod = false;
    $pmCheck = $db->query("SHOW COLUMNS FROM donors LIKE 'preferred_payment_method'");
    if ($pmCheck && $pmCheck->num_rows > 0) {
        $hasPaymentMethod = true;
    }

    $sql = "
        SELECT
            d.id,
            d.name,
            d.phone,
            d.total_pledged,
            d.total_paid,
            d.balance,
            d.payment_status,
            d.donor_type,
            d.created_at
            " . ($hasDataSource ? ", d.data_source" : ", 'unknown' AS data_source") . "
            " . ($hasCity ? ", d.city" : ", NULL AS city") . "
            " . ($hasPaymentMethod ? ", d.preferred_payment_method" : ", NULL AS preferred_payment_method") . "
        FROM donors d
        ORDER BY d.name ASC
    ";

    $result = $db->query($sql);
    $donors = [];
    while ($row = $result->fetch_assoc()) {
        $phone = trim((string)($row['phone'] ?? ''));
        $phoneNorm = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phoneNorm) === 13 && str_starts_with($phoneNorm, '44')) {
            $phoneNorm = substr($phoneNorm, 2);
        } elseif (strlen($phoneNorm) === 12 && str_starts_with($phoneNorm, '44')) {
            $phoneNorm = substr($phoneNorm, 2);
        }
        if (strlen($phoneNorm) === 10 && str_starts_with($phoneNorm, '7')) {
            $phoneNorm = '0' . $phoneNorm;
        }

        $donors[] = [
            'id' => (int)($row['id'] ?? 0),
            'name' => (string)($row['name'] ?? ''),
            'phone' => $phone,
            'phone_normalized' => $phoneNorm,
            'total_pledged' => (float)($row['total_pledged'] ?? 0),
            'total_paid' => (float)($row['total_paid'] ?? 0),
            'balance' => (float)($row['balance'] ?? 0),
            'payment_status' => (string)($row['payment_status'] ?? ''),
            'donor_type' => (string)($row['donor_type'] ?? ''),
            'data_source' => (string)($row['data_source'] ?? 'unknown'),
            'city' => (string)($row['city'] ?? ''),
            'payment_method' => (string)($row['preferred_payment_method'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
        ];
    }

    echo json_encode([
        'success' => true,
        'donors' => $donors,
        'total' => count($donors),
        'has_data_source' => $hasDataSource,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
