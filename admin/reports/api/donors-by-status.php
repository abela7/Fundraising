<?php
declare(strict_types=1);

require_once '../../../config/db.php';
require_once '../../../shared/auth.php';

header('Content-Type: application/json');

require_login();
require_admin();

$status = strtolower(trim($_GET['status'] ?? 'paying'));
if (!in_array($status, ['paying', 'completed'], true)) {
    $status = 'paying';
}

$donorSearch = trim($_GET['donor'] ?? '');
$pledgedMin = $_GET['pledged_min'] !== '' && $_GET['pledged_min'] !== null ? (float)$_GET['pledged_min'] : null;
$pledgedMax = $_GET['pledged_max'] !== '' && $_GET['pledged_max'] !== null ? (float)$_GET['pledged_max'] : null;
$paidMin = $_GET['paid_min'] !== '' && $_GET['paid_min'] !== null ? (float)$_GET['paid_min'] : null;
$paidMax = $_GET['paid_max'] !== '' && $_GET['paid_max'] !== null ? (float)$_GET['paid_max'] : null;
$balanceMin = $_GET['balance_min'] !== '' && $_GET['balance_min'] !== null ? (float)$_GET['balance_min'] : null;
$balanceMax = $_GET['balance_max'] !== '' && $_GET['balance_max'] !== null ? (float)$_GET['balance_max'] : null;
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = min(100, max(10, (int)($_GET['per_page'] ?? 25)));
$offset = ($page - 1) * $perPage;

$sortBy = strtolower(trim($_GET['sort_by'] ?? 'balance'));
$sortOrder = strtolower(trim($_GET['sort_order'] ?? 'desc'));
$validSortColumns = [
    'donor' => 'd.name',
    'pledged' => 'd.total_pledged',
    'paid' => 'd.total_paid',
    'balance' => 'd.balance',
];
if (!isset($validSortColumns[$sortBy])) {
    $sortBy = $status === 'paying' ? 'balance' : 'paid';
}
if (!in_array($sortOrder, ['asc', 'desc'], true)) {
    $sortOrder = 'desc';
}
$orderColumn = $validSortColumns[$sortBy];

try {
    $db = db();

    $hasPaymentStatusCol = $db->query("SHOW COLUMNS FROM donors LIKE 'payment_status'")->num_rows > 0;

    $where = ['d.total_pledged > 0'];
    if ($hasPaymentStatusCol) {
        $where[] = $status === 'paying'
            ? "d.payment_status = 'paying'"
            : "d.payment_status = 'completed'";
    } else {
        if ($status === 'paying') {
            $where[] = 'd.total_paid > 0';
            $where[] = 'd.balance > 0.01';
        } else {
            $where[] = 'd.balance <= 0.01';
        }
    }

    $params = [];
    $types = '';
    if ($donorSearch !== '') {
        $where[] = '(d.name LIKE ? OR d.phone LIKE ?)';
        $searchParam = '%' . $donorSearch . '%';
        $params[] = $searchParam;
        $params[] = $searchParam;
        $types .= 'ss';
    }
    if ($pledgedMin !== null && $pledgedMin >= 0) {
        $where[] = 'd.total_pledged >= ?';
        $params[] = $pledgedMin;
        $types .= 'd';
    }
    if ($pledgedMax !== null && $pledgedMax > 0) {
        $where[] = 'd.total_pledged <= ?';
        $params[] = $pledgedMax;
        $types .= 'd';
    }
    if ($paidMin !== null && $paidMin >= 0) {
        $where[] = 'd.total_paid >= ?';
        $params[] = $paidMin;
        $types .= 'd';
    }
    if ($paidMax !== null && $paidMax > 0) {
        $where[] = 'd.total_paid <= ?';
        $params[] = $paidMax;
        $types .= 'd';
    }
    if ($balanceMin !== null && $balanceMin >= 0) {
        $where[] = 'd.balance >= ?';
        $params[] = $balanceMin;
        $types .= 'd';
    }
    if ($balanceMax !== null && $balanceMax > 0) {
        $where[] = 'd.balance <= ?';
        $params[] = $balanceMax;
        $types .= 'd';
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

    $orderBy = "{$orderColumn} {$sortOrder}, d.name ASC";
    $cols = "d.id, d.name, d.phone, d.total_pledged, d.total_paid, d.balance";
    if ($hasPaymentStatusCol) {
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
                'payment_status' => $hasPaymentStatusCol ? ucfirst((string)($r['payment_status'] ?? '')) : ucfirst($status),
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
                'payment_status' => $hasPaymentStatusCol ? ucfirst((string)($r['payment_status'] ?? '')) : ucfirst($status),
            ];
        }
    }

    $totalPages = $perPage > 0 ? (int)ceil($totalRows / $perPage) : 1;

    echo json_encode([
        'status' => $status,
        'total_count' => $totalRows,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => $totalPages,
        'rows' => $rows,
        'filters' => [
            'donor' => $donorSearch,
            'pledged_min' => $pledgedMin,
            'pledged_max' => $pledgedMax,
            'paid_min' => $paidMin,
            'paid_max' => $paidMax,
            'balance_min' => $balanceMin,
            'balance_max' => $balanceMax,
        ],
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Database error',
        'message' => $e->getMessage(),
    ]);
    error_log('Donors By Status API Error: ' . $e->getMessage());
}
