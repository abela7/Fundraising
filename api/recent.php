<?php
declare(strict_types=1);
error_reporting(0);
ini_set('display_errors', '0');

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$cacheDir = sys_get_temp_dir() . '/projector_rl';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0700, true);
}
$cacheFile = $cacheDir . '/' . md5($ip) . '_recent.json';
$now = time();
$window = 60;
$maxRequests = 90;
$data = file_exists($cacheFile) ? (json_decode((string) file_get_contents($cacheFile), true) ?? []) : [];
$data = array_values(array_filter($data, static fn($t) => $t > $now - $window));
if (count($data) >= $maxRequests) {
    header('Content-Type: application/json');
    http_response_code(429);
    echo json_encode(['items' => [], 'has_more' => false, 'error' => 'Too many requests']);
    exit();
}
$data[] = $now;
file_put_contents($cacheFile, json_encode($data));

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['items' => [], 'has_more' => false]);
    exit;
}

try {
    $db = db();
    $settings = $db->query('SELECT currency_code FROM settings WHERE id=1')->fetch_assoc();
    $currency = $settings['currency_code'] ?? 'GBP';

    $filter = (string) ($_GET['type'] ?? 'all');
    if (!in_array($filter, ['all', 'paid', 'pledge'], true)) {
        $filter = 'all';
    }
    $limit = min(50, max(1, (int) ($_GET['limit'] ?? 20)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));

    $hasPp = $db->query("SHOW TABLES LIKE 'pledge_payments'")->num_rows > 0;
    $union = "
        (SELECT p.amount, 'pledge' AS type, p.approved_at
           FROM pledges p
          WHERE p.status = 'approved')
        UNION ALL
        (SELECT pay.amount, 'paid' AS type, pay.received_at AS approved_at
           FROM payments pay
          WHERE pay.status = 'approved')
    ";
    if ($hasPp) {
        $union .= "
            UNION ALL
            (SELECT pp.amount, 'paid' AS type, pp.created_at AS approved_at
               FROM pledge_payments pp
              WHERE pp.status = 'confirmed')
        ";
    }

    $whereSql = '';
    $types = '';
    $params = [];
    if ($filter !== 'all') {
        $whereSql = ' WHERE type = ?';
        $types .= 's';
        $params[] = $filter;
    }

    $countSql = "SELECT COUNT(*) AS cnt FROM ({$union}) AS feed{$whereSql}";
    $countStmt = $db->prepare($countSql);
    if ($types !== '') {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $total = (int) ($countStmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    $countStmt->close();

    $listSql = "SELECT amount, type, approved_at FROM ({$union}) AS feed{$whereSql} ORDER BY approved_at DESC LIMIT ? OFFSET ?";
    $listTypes = $types . 'ii';
    $listParams = $params;
    $listParams[] = $limit;
    $listParams[] = $offset;
    $listStmt = $db->prepare($listSql);
    $listStmt->bind_param($listTypes, ...$listParams);
    $listStmt->execute();
    $res = $listStmt->get_result();

    $items = [];
    while ($row = $res->fetch_assoc()) {
        $kind = $row['type'] === 'paid' ? 'paid' : 'pledge';
        $verb = $kind === 'paid' ? 'paid' : 'pledged';
        $items[] = [
            'text' => 'Kind Donor ' . $verb . ' ' . $currency . ' ' . number_format((float) $row['amount'], 0),
            'type' => $kind,
            'approved_at' => $row['approved_at'],
            'is_anonymous' => true,
        ];
    }
    $listStmt->close();

    echo json_encode([
        'items' => $items,
        'total' => $total,
        'offset' => $offset,
        'limit' => $limit,
        'has_more' => ($offset + count($items)) < $total,
    ]);
} catch (Throwable $e) {
    error_log('Error in recent.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['items' => [], 'has_more' => false]);
}
