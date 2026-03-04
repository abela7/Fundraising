<?php
declare(strict_types=1);

require_once '../../../config/db.php';
require_once '../../../shared/auth.php';

header('Content-Type: application/json');

require_login();
require_admin();

try {
    $db = db();

    $donorSearch = trim($_GET['donor'] ?? '');
    $balanceMismatchOnly = !empty($_GET['balance_mismatch']);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 25)));
    $offset = ($page - 1) * $perPage;

    $hasPaymentStatus = false;
    $psCheck = $db->query("SHOW COLUMNS FROM donors LIKE 'payment_status'");
    if ($psCheck && $psCheck->num_rows > 0) {
        $hasPaymentStatus = true;
    }

    $sortBy = strtolower(trim($_GET['sort_by'] ?? 'balance'));
    $sortOrder = strtolower(trim($_GET['sort_order'] ?? 'desc'));
    $validSortColumns = [
        'id' => 'd.id',
        'donor' => 'd.name',
        'pledged' => 'd.total_pledged',
        'paid' => 'd.total_paid',
        'balance' => 'd.balance',
        'status' => $hasPaymentStatus ? 'd.payment_status' : 'd.balance',
    ];
    if (!isset($validSortColumns[$sortBy])) {
        $sortBy = 'balance';
    }
    if (!in_array($sortOrder, ['asc', 'desc'], true)) {
        $sortOrder = 'desc';
    }
    $orderColumn = $validSortColumns[$sortBy];

    $where = ['d.total_pledged > 0', 'd.balance > 0.01'];
    $params = [];
    $types = '';

    if ($donorSearch !== '') {
        $where[] = "(d.name LIKE ? OR d.phone LIKE ?)";
        $searchParam = '%' . $donorSearch . '%';
        $params[] = $searchParam;
        $params[] = $searchParam;
        $types .= 'ss';
    }

    if ($balanceMismatchOnly) {
        // Balance > pledged is impossible (outstanding cannot exceed what was pledged) — indicates data error
        $where[] = "d.balance > d.total_pledged";
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);

    $countSql = "SELECT COUNT(*) as total FROM donors d {$whereClause}";
    if (!empty($params)) {
        $stmt = $db->prepare($countSql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $totalRows = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
    } else {
        $totalRows = (int)($db->query($countSql)->fetch_assoc()['total'] ?? 0);
    }

    $sumSql = "SELECT COALESCE(SUM(d.balance), 0) as total_outstanding FROM donors d {$whereClause}";
    if (!empty($params)) {
        $stmt = $db->prepare($sumSql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $totalOutstanding = (float)($stmt->get_result()->fetch_assoc()['total_outstanding'] ?? 0);
        $stmt->close();
    } else {
        $totalOutstanding = (float)($db->query($sumSql)->fetch_assoc()['total_outstanding'] ?? 0);
    }

    $orderBy = "{$orderColumn} {$sortOrder}, d.name ASC";
    $cols = "d.id, d.name, d.phone, d.total_pledged, d.total_paid, d.balance";
    if ($hasPaymentStatus) {
        $cols .= ", d.payment_status";
    }
    $dataSql = "
        SELECT {$cols}
        FROM donors d
        {$whereClause}
        ORDER BY {$orderBy}
        LIMIT {$perPage} OFFSET {$offset}
    ";

    $rows = [];
    if (!empty($params)) {
        $stmt = $db->prepare($dataSql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $rows[] = [
                'id' => (int)($r['id'] ?? 0),
                'donor_name' => (string)($r['name'] ?? 'Unknown'),
                'donor_phone' => (string)($r['phone'] ?? ''),
                'total_pledged' => (float)($r['total_pledged'] ?? 0),
                'total_paid' => (float)($r['total_paid'] ?? 0),
                'balance' => (float)($r['balance'] ?? 0),
                'payment_status' => $hasPaymentStatus ? ucfirst((string)($r['payment_status'] ?? '')) : '',
            ];
        }
        $stmt->close();
    } else {
        $res = $db->query($dataSql);
        while ($r = $res->fetch_assoc()) {
            $rows[] = [
                'id' => (int)($r['id'] ?? 0),
                'donor_name' => (string)($r['name'] ?? 'Unknown'),
                'donor_phone' => (string)($r['phone'] ?? ''),
                'total_pledged' => (float)($r['total_pledged'] ?? 0),
                'total_paid' => (float)($r['total_paid'] ?? 0),
                'balance' => (float)($r['balance'] ?? 0),
                'payment_status' => $hasPaymentStatus ? ucfirst((string)($r['payment_status'] ?? '')) : '',
            ];
        }
    }

    $totalPages = $perPage > 0 ? (int)ceil($totalRows / $perPage) : 1;

    echo json_encode([
        'total_outstanding' => $totalOutstanding,
        'total_count' => $totalRows,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => $totalPages,
        'rows' => $rows,
        'filters' => ['donor' => $donorSearch, 'balance_mismatch' => $balanceMismatchOnly],
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Database error',
        'message' => $e->getMessage(),
    ]);
    error_log('Outstanding Pledged API Error: ' . $e->getMessage());
}
