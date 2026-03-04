<?php
declare(strict_types=1);

require_once '../../../config/db.php';
require_once '../../../shared/auth.php';

header('Content-Type: application/json');

require_login();
require_admin();

try {
    $db = db();

    // Filters
    $dateFrom = trim($_GET['date_from'] ?? '');
    $dateTo = trim($_GET['date_to'] ?? '');
    $donorSearch = trim($_GET['donor'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 25)));
    $offset = ($page - 1) * $perPage;

    $hasPledgeDate = false;
    $pcCheck = $db->query("SHOW COLUMNS FROM pledges LIKE 'pledge_date'");
    if ($pcCheck && $pcCheck->num_rows > 0) {
        $hasPledgeDate = true;
    }

    // Sort
    $sortBy = strtolower(trim($_GET['sort_by'] ?? 'created_at'));
    $sortOrder = strtolower(trim($_GET['sort_order'] ?? 'desc'));
    $validSortColumns = [
        'id' => 'p.id',
        'donor' => 'COALESCE(d.name, p.donor_name)',
        'amount' => 'p.amount',
        'created_at' => 'p.created_at',
        'pledge_date' => $hasPledgeDate ? 'p.pledge_date' : 'p.created_at',
    ];
    if (!isset($validSortColumns[$sortBy])) {
        $sortBy = 'created_at';
    }
    if (!in_array($sortOrder, ['asc', 'desc'], true)) {
        $sortOrder = 'desc';
    }
    $orderColumn = $validSortColumns[$sortBy];

    $where = ["p.status = 'approved'"];
    $params = [];
    $types = '';

    if ($dateFrom !== '') {
        $where[] = $hasPledgeDate ? "COALESCE(p.pledge_date, p.created_at) >= ?" : "p.created_at >= ?";
        $params[] = $dateFrom . ' 00:00:00';
        $types .= 's';
    }
    if ($dateTo !== '') {
        $where[] = $hasPledgeDate ? "COALESCE(p.pledge_date, p.created_at) <= ?" : "p.created_at <= ?";
        $params[] = $dateTo . ' 23:59:59';
        $types .= 's';
    }
    if ($donorSearch !== '') {
        $where[] = "(d.name LIKE ? OR d.phone LIKE ? OR p.donor_name LIKE ? OR p.donor_phone LIKE ?)";
        $searchParam = '%' . $donorSearch . '%';
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $types .= 'ssss';
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);

    $joinClause = 'LEFT JOIN donors d ON p.donor_id = d.id';

    // Total count
    $countSql = "
        SELECT COUNT(*) as total
        FROM pledges p
        {$joinClause}
        {$whereClause}
    ";
    if (!empty($params)) {
        $stmt = $db->prepare($countSql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $totalRows = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
    } else {
        $totalRows = (int)($db->query($countSql)->fetch_assoc()['total'] ?? 0);
    }

    // Total sum
    $sumSql = "
        SELECT COALESCE(SUM(p.amount), 0) as total_amount
        FROM pledges p
        {$joinClause}
        {$whereClause}
    ";
    if (!empty($params)) {
        $stmt = $db->prepare($sumSql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $totalAmount = (float)($stmt->get_result()->fetch_assoc()['total_amount'] ?? 0);
        $stmt->close();
    } else {
        $totalAmount = (float)($db->query($sumSql)->fetch_assoc()['total_amount'] ?? 0);
    }

    // Fetch rows with pagination
    $orderBy = "{$orderColumn} {$sortOrder}, p.id DESC";
    $pledgeDateCol = $hasPledgeDate ? 'p.pledge_date' : 'p.created_at';
    $dataSql = "
        SELECT
            p.id,
            p.donor_id,
            COALESCE(d.name, p.donor_name) as donor_name,
            COALESCE(d.phone, p.donor_phone) as donor_phone,
            p.amount,
            p.status,
            p.created_at,
            {$pledgeDateCol} as pledge_date,
            p.notes
        FROM pledges p
        {$joinClause}
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
                'donor_id' => (int)($r['donor_id'] ?? 0),
                'donor_name' => (string)($r['donor_name'] ?? 'Unknown'),
                'donor_phone' => (string)($r['donor_phone'] ?? ''),
                'amount' => (float)($r['amount'] ?? 0),
                'status' => (string)($r['status'] ?? ''),
                'created_at' => (string)($r['created_at'] ?? ''),
                'pledge_date' => (string)($r['pledge_date'] ?? ''),
                'notes' => (string)($r['notes'] ?? ''),
            ];
        }
        $stmt->close();
    } else {
        $res = $db->query($dataSql);
        while ($r = $res->fetch_assoc()) {
            $rows[] = [
                'id' => (int)($r['id'] ?? 0),
                'donor_id' => (int)($r['donor_id'] ?? 0),
                'donor_name' => (string)($r['donor_name'] ?? 'Unknown'),
                'donor_phone' => (string)($r['donor_phone'] ?? ''),
                'amount' => (float)($r['amount'] ?? 0),
                'status' => (string)($r['status'] ?? ''),
                'created_at' => (string)($r['created_at'] ?? ''),
                'pledge_date' => (string)($r['pledge_date'] ?? ''),
                'notes' => (string)($r['notes'] ?? ''),
            ];
        }
    }

    $totalPages = $perPage > 0 ? (int)ceil($totalRows / $perPage) : 1;

    echo json_encode([
        'total_amount' => $totalAmount,
        'total_count' => $totalRows,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => $totalPages,
        'rows' => $rows,
        'filters' => [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'donor' => $donorSearch,
        ],
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Database error',
        'message' => $e->getMessage(),
    ]);
    error_log('Total Pledged Approved API Error: ' . $e->getMessage());
}
