<?php
declare(strict_types=1);

require_once '../../../config/db.php';
require_once '../../../shared/auth.php';

header('Content-Type: application/json');

require_login();
require_admin();

/**
 * @return list<array<string,mixed>>
 */
function fetch_assoc_rows(mysqli_result $result): array
{
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    return $rows;
}

function payment_method_label(string $method): string
{
    return match ($method) {
        'bank', 'bank_transfer', 'transfer' => 'Bank Transfer',
        'card' => 'Card',
        'cash' => 'Cash',
        'cheque' => 'Cheque',
        default => $method !== '' ? ucfirst($method) : 'Other',
    };
}

/**
 * @param list<mixed> $params
 */
function run_query(mysqli $db, string $sql, string $types, array $params): mysqli_result
{
    if ($types === '' || $params === []) {
        $result = $db->query($sql);
        if ($result === false) {
            throw new RuntimeException('Query failed.');
        }

        return $result;
    }

    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        throw new RuntimeException('Query failed.');
    }

    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Query failed.');
    }

    $result = $stmt->get_result();
    $stmt->close();
    if ($result === false) {
        throw new RuntimeException('Query failed.');
    }

    return $result;
}

try {
    $db = db();

    $dsCheck = $db->query("SHOW COLUMNS FROM donors LIKE 'data_source'");
    if (!$dsCheck || $dsCheck->num_rows === 0) {
        http_response_code(404);
        echo json_encode([
            'error' => 'Data source is not available on this system.',
            'enabled' => false,
        ]);
        exit;
    }

    $source = strtolower(trim((string)($_GET['source'] ?? 'old_system')));
    if (!in_array($source, ['old_system', 'new_system'], true)) {
        $source = 'old_system';
    }

    $view = strtolower(trim((string)($_GET['view'] ?? 'donors')));
    if (!in_array($view, ['donors', 'pledges', 'payments', 'outstanding'], true)) {
        $view = 'donors';
    }

    $statusFilter = strtolower(trim((string)($_GET['status'] ?? '')));
    if (!in_array($statusFilter, ['paying', 'completed', 'not_started'], true)) {
        $statusFilter = '';
    }

    $dateFrom = trim((string)($_GET['date_from'] ?? ''));
    $dateTo = trim((string)($_GET['date_to'] ?? ''));
    $donorSearch = trim((string)($_GET['donor'] ?? ''));
    $paymentMethod = trim((string)($_GET['payment_method'] ?? ''));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(10000, max(10, (int)($_GET['per_page'] ?? 25)));
    $offset = ($page - 1) * $perPage;

    $hasPaymentStatus = false;
    $psCheck = $db->query("SHOW COLUMNS FROM donors LIKE 'payment_status'");
    if ($psCheck && $psCheck->num_rows > 0) {
        $hasPaymentStatus = true;
    }

    $hasPledgePayments = false;
    $ppCheck = $db->query("SHOW TABLES LIKE 'pledge_payments'");
    if ($ppCheck && $ppCheck->num_rows > 0) {
        $hasPledgePayments = true;
    }

    $hasPledgeDate = false;
    $pdCheck = $db->query("SHOW COLUMNS FROM pledges LIKE 'pledge_date'");
    if ($pdCheck && $pdCheck->num_rows > 0) {
        $hasPledgeDate = true;
    }

    $payingExpr = $hasPaymentStatus
        ? "d.payment_status = 'paying'"
        : '(d.total_paid > 0 AND d.balance > 0.01)';
    $completedExpr = $hasPaymentStatus
        ? "d.payment_status = 'completed'"
        : 'd.balance <= 0.01';
    $notStartedExpr = $hasPaymentStatus
        ? "d.payment_status = 'not_started'"
        : '(d.total_paid = 0 AND d.balance > 0.01)';

    $summarySql = "
        SELECT
            COUNT(DISTINCT d.id) AS donor_count,
            COALESCE(SUM(d.total_pledged), 0) AS total_pledged,
            COALESCE(SUM(d.total_paid), 0) AS total_paid,
            COALESCE(SUM(d.balance), 0) AS total_balance,
            SUM(CASE WHEN d.total_pledged > 0 AND {$payingExpr} THEN 1 ELSE 0 END) AS donors_paying,
            SUM(CASE WHEN d.total_pledged > 0 AND {$completedExpr} THEN 1 ELSE 0 END) AS donors_completed,
            SUM(CASE WHEN d.total_pledged > 0 AND {$notStartedExpr} THEN 1 ELSE 0 END) AS donors_not_started
        FROM donors d
        WHERE d.data_source = ?
    ";
    $summaryRow = run_query($db, $summarySql, 's', [$source])->fetch_assoc() ?: [];
    $totalPledged = (float)($summaryRow['total_pledged'] ?? 0);
    $totalPaid = (float)($summaryRow['total_paid'] ?? 0);
    $summary = [
        'source' => $source,
        'label' => $source === 'old_system' ? 'Old System (Imported)' : 'New System',
        'donor_count' => (int)($summaryRow['donor_count'] ?? 0),
        'total_pledged' => $totalPledged,
        'total_paid' => $totalPaid,
        'total_balance' => (float)($summaryRow['total_balance'] ?? 0),
        'collection_rate' => $totalPledged > 0 ? round(($totalPaid / $totalPledged) * 100, 1) : 0.0,
        'donors_paying' => (int)($summaryRow['donors_paying'] ?? 0),
        'donors_completed' => (int)($summaryRow['donors_completed'] ?? 0),
        'donors_not_started' => (int)($summaryRow['donors_not_started'] ?? 0),
    ];

    $sortBy = strtolower(trim((string)($_GET['sort_by'] ?? '')));
    $sortOrder = strtolower(trim((string)($_GET['sort_order'] ?? '')));
    if (!in_array($sortOrder, ['asc', 'desc'], true)) {
        $sortOrder = 'desc';
    }

    $rows = [];
    $totalRows = 0;
    $viewTotal = 0.0;
    $viewLabel = '';

    if ($view === 'pledges') {
        $validSort = [
            'donor' => 'COALESCE(d.name, p.donor_name)',
            'amount' => 'p.amount',
            'created_at' => 'p.created_at',
            'pledge_date' => $hasPledgeDate ? 'p.pledge_date' : 'p.created_at',
        ];
        if (!isset($validSort[$sortBy])) {
            $sortBy = 'created_at';
            $sortOrder = 'desc';
        }
        $where = ["d.data_source = ?", "p.status = 'approved'"];
        $params = [$source];
        $types = 's';
        if ($dateFrom !== '') {
            $where[] = $hasPledgeDate
                ? 'COALESCE(p.pledge_date, p.created_at) >= ?'
                : 'p.created_at >= ?';
            $params[] = $dateFrom . ' 00:00:00';
            $types .= 's';
        }
        if ($dateTo !== '') {
            $where[] = $hasPledgeDate
                ? 'COALESCE(p.pledge_date, p.created_at) <= ?'
                : 'p.created_at <= ?';
            $params[] = $dateTo . ' 23:59:59';
            $types .= 's';
        }
        if ($donorSearch !== '') {
            $where[] = '(d.name LIKE ? OR d.phone LIKE ? OR p.donor_name LIKE ? OR p.donor_phone LIKE ?)';
            $search = '%' . $donorSearch . '%';
            array_push($params, $search, $search, $search, $search);
            $types .= 'ssss';
        }
        $whereClause = 'WHERE ' . implode(' AND ', $where);
        $join = 'FROM pledges p INNER JOIN donors d ON p.donor_id = d.id';
        $totalRows = (int)(run_query(
            $db,
            "SELECT COUNT(*) AS total {$join} {$whereClause}",
            $types,
            $params
        )->fetch_assoc()['total'] ?? 0);
        $viewTotal = (float)(run_query(
            $db,
            "SELECT COALESCE(SUM(p.amount), 0) AS total_amount {$join} {$whereClause}",
            $types,
            $params
        )->fetch_assoc()['total_amount'] ?? 0);
        $pledgeDateCol = $hasPledgeDate ? 'p.pledge_date' : 'p.created_at';
        $dataSql = "
            SELECT
                p.id,
                p.donor_id,
                COALESCE(d.name, p.donor_name) AS donor_name,
                COALESCE(d.phone, p.donor_phone) AS donor_phone,
                p.amount,
                p.status,
                p.created_at,
                {$pledgeDateCol} AS pledge_date
            {$join}
            {$whereClause}
            ORDER BY {$validSort[$sortBy]} {$sortOrder}, p.id DESC
            LIMIT {$perPage} OFFSET {$offset}
        ";
        foreach (fetch_assoc_rows(run_query($db, $dataSql, $types, $params)) as $r) {
            $rows[] = [
                'id' => (int)($r['id'] ?? 0),
                'donor_id' => (int)($r['donor_id'] ?? 0),
                'donor_name' => (string)($r['donor_name'] ?? 'Unknown'),
                'donor_phone' => (string)($r['donor_phone'] ?? ''),
                'amount' => (float)($r['amount'] ?? 0),
                'status' => (string)($r['status'] ?? ''),
                'created_at' => (string)($r['created_at'] ?? ''),
                'pledge_date' => (string)($r['pledge_date'] ?? ''),
            ];
        }
        $viewLabel = 'approved pledges';
    } elseif ($view === 'payments') {
        if (!$hasPledgePayments) {
            echo json_encode([
                'enabled' => false,
                'view' => $view,
                'summary' => $summary,
                'total_amount' => 0,
                'total_count' => 0,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => 1,
                'rows' => [],
            ]);
            exit;
        }

        $validSort = [
            'donor' => 'd.name',
            'amount' => 'pp.amount',
            'method' => 'pp.payment_method',
            'payment_date' => 'pp.payment_date',
            'reference' => 'pp.reference_number',
            'approved_by' => 'approver.name',
        ];
        if (!isset($validSort[$sortBy])) {
            $sortBy = 'payment_date';
            $sortOrder = 'desc';
        }
        $where = ["d.data_source = ?", "pp.status = 'confirmed'"];
        $params = [$source];
        $types = 's';
        if ($dateFrom !== '') {
            $where[] = 'pp.payment_date >= ?';
            $params[] = $dateFrom . ' 00:00:00';
            $types .= 's';
        }
        if ($dateTo !== '') {
            $where[] = 'pp.payment_date <= ?';
            $params[] = $dateTo . ' 23:59:59';
            $types .= 's';
        }
        if ($donorSearch !== '') {
            $where[] = '(d.name LIKE ? OR d.phone LIKE ? OR pp.reference_number LIKE ?)';
            $search = '%' . $donorSearch . '%';
            array_push($params, $search, $search, $search);
            $types .= 'sss';
        }
        if ($paymentMethod !== '') {
            $validMethods = ['bank', 'bank_transfer', 'card', 'cash', 'cheque', 'other'];
            if (in_array($paymentMethod, $validMethods, true)) {
                if ($paymentMethod === 'bank' || $paymentMethod === 'bank_transfer') {
                    $where[] = "pp.payment_method IN ('bank', 'bank_transfer', 'transfer')";
                } else {
                    $where[] = 'pp.payment_method = ?';
                    $params[] = $paymentMethod;
                    $types .= 's';
                }
            }
        }
        $whereClause = 'WHERE ' . implode(' AND ', $where);
        $join = 'FROM pledge_payments pp
            INNER JOIN donors d ON pp.donor_id = d.id
            LEFT JOIN users approver ON pp.approved_by_user_id = approver.id';
        $totalRows = (int)(run_query(
            $db,
            "SELECT COUNT(*) AS total {$join} {$whereClause}",
            $types,
            $params
        )->fetch_assoc()['total'] ?? 0);
        $viewTotal = (float)(run_query(
            $db,
            "SELECT COALESCE(SUM(pp.amount), 0) AS total_amount {$join} {$whereClause}",
            $types,
            $params
        )->fetch_assoc()['total_amount'] ?? 0);
        $dataSql = "
            SELECT
                pp.id,
                pp.donor_id,
                pp.amount,
                pp.payment_method,
                pp.payment_date,
                pp.reference_number,
                d.name AS donor_name,
                d.phone AS donor_phone,
                approver.name AS approved_by_name
            {$join}
            {$whereClause}
            ORDER BY {$validSort[$sortBy]} {$sortOrder}, pp.id DESC
            LIMIT {$perPage} OFFSET {$offset}
        ";
        foreach (fetch_assoc_rows(run_query($db, $dataSql, $types, $params)) as $r) {
            $method = (string)($r['payment_method'] ?? '');
            $rows[] = [
                'id' => (int)($r['id'] ?? 0),
                'donor_id' => (int)($r['donor_id'] ?? 0),
                'donor_name' => (string)($r['donor_name'] ?? 'Unknown'),
                'donor_phone' => (string)($r['donor_phone'] ?? ''),
                'amount' => (float)($r['amount'] ?? 0),
                'payment_method' => payment_method_label($method),
                'payment_method_raw' => $method,
                'payment_date' => (string)($r['payment_date'] ?? ''),
                'reference_number' => (string)($r['reference_number'] ?? ''),
                'approved_by' => (string)($r['approved_by_name'] ?? ''),
            ];
        }
        $viewLabel = 'confirmed payments';
    } else {
        $isOutstanding = $view === 'outstanding';
        $validSort = [
            'donor' => 'd.name',
            'pledged' => 'd.total_pledged',
            'paid' => 'd.total_paid',
            'balance' => 'd.balance',
            'status' => $hasPaymentStatus ? 'd.payment_status' : 'd.balance',
        ];
        if (!isset($validSort[$sortBy])) {
            $sortBy = $isOutstanding ? 'balance' : 'donor';
            $sortOrder = $isOutstanding ? 'desc' : 'asc';
        }
        $where = ['d.data_source = ?'];
        $params = [$source];
        $types = 's';
        if ($isOutstanding) {
            $where[] = 'd.total_pledged > 0';
            $where[] = 'd.balance > 0.01';
        }
        if ($statusFilter === 'paying') {
            $where[] = 'd.total_pledged > 0';
            $where[] = $payingExpr;
        } elseif ($statusFilter === 'completed') {
            $where[] = 'd.total_pledged > 0';
            $where[] = $completedExpr;
        } elseif ($statusFilter === 'not_started') {
            $where[] = 'd.total_pledged > 0';
            $where[] = $notStartedExpr;
        }
        if ($donorSearch !== '') {
            $where[] = '(d.name LIKE ? OR d.phone LIKE ?)';
            $search = '%' . $donorSearch . '%';
            array_push($params, $search, $search);
            $types .= 'ss';
        }
        $whereClause = 'WHERE ' . implode(' AND ', $where);
        $totalRows = (int)(run_query(
            $db,
            "SELECT COUNT(*) AS total FROM donors d {$whereClause}",
            $types,
            $params
        )->fetch_assoc()['total'] ?? 0);
        $sumCol = $isOutstanding ? 'd.balance' : 'd.total_pledged';
        $viewTotal = (float)(run_query(
            $db,
            "SELECT COALESCE(SUM({$sumCol}), 0) AS total_amount FROM donors d {$whereClause}",
            $types,
            $params
        )->fetch_assoc()['total_amount'] ?? 0);
        $statusSelect = $hasPaymentStatus
            ? 'd.payment_status AS payment_status'
            : "CASE
                WHEN d.total_pledged > 0 AND d.balance <= 0.01 THEN 'completed'
                WHEN d.total_pledged > 0 AND d.total_paid > 0 AND d.balance > 0.01 THEN 'paying'
                WHEN d.total_pledged > 0 THEN 'not_started'
                ELSE 'no_pledge'
              END AS payment_status";
        $dataSql = "
            SELECT
                d.id,
                d.name AS donor_name,
                d.phone AS donor_phone,
                d.total_pledged,
                d.total_paid,
                d.balance,
                {$statusSelect}
            FROM donors d
            {$whereClause}
            ORDER BY {$validSort[$sortBy]} {$sortOrder}, d.id ASC
            LIMIT {$perPage} OFFSET {$offset}
        ";
        foreach (fetch_assoc_rows(run_query($db, $dataSql, $types, $params)) as $r) {
            $rows[] = [
                'id' => (int)($r['id'] ?? 0),
                'donor_id' => (int)($r['id'] ?? 0),
                'donor_name' => (string)($r['donor_name'] ?? 'Unknown'),
                'donor_phone' => (string)($r['donor_phone'] ?? ''),
                'pledged' => (float)($r['total_pledged'] ?? 0),
                'paid' => (float)($r['total_paid'] ?? 0),
                'balance' => (float)($r['balance'] ?? 0),
                'status' => (string)($r['payment_status'] ?? ''),
            ];
        }
        $viewLabel = $isOutstanding ? 'donors with outstanding balance' : 'donors';
    }

    $totalPages = $perPage > 0 ? (int)ceil($totalRows / $perPage) : 1;

    echo json_encode([
        'enabled' => true,
        'view' => $view,
        'view_label' => $viewLabel,
        'summary' => $summary,
        'total_amount' => $viewTotal,
        'total_count' => $totalRows,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => $totalPages,
        'rows' => $rows,
        'filters' => [
            'source' => $source,
            'view' => $view,
            'status' => $statusFilter,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'donor' => $donorSearch,
            'payment_method' => $paymentMethod,
        ],
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Database error',
        'message' => 'Failed to load data source report.',
    ]);
    error_log('Data source detail API error: ' . $e->getMessage());
}
