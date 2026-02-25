<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';

require_admin();

$page_title = 'Public Donation Requests';
$current_user = current_user();

$actionMsg = '';
$isError = false;
$showDebug = (($_GET['debug'] ?? '') === '1');

$db = null;
$dbConnected = false;
try {
    $db = db();
    $dbConnected = true;
} catch (Throwable $e) {
    $isError = true;
    $actionMsg = 'Unable to connect to the database.';
    error_log('Admin Public Donations - DB connection failed: ' . $e->getMessage());
}

function bind_query_params(mysqli_stmt $stmt, string $types, array $params): void {
    if (empty($params)) {
        return;
    }

    $bind = [$types];
    foreach ($params as $key => $value) {
        $bind[] = &$params[$key];
    }

    call_user_func_array([$stmt, 'bind_param'], $bind);
}

function table_column_map(mysqli $db, string $tableName): array {
    $map = [];
    $result = $db->query("SHOW COLUMNS FROM `{$tableName}`");
    if (!$result) {
        return $map;
    }

    while ($row = $result->fetch_assoc()) {
        $field = (string)($row['Field'] ?? '');
        if ($field !== '') {
            $map[$field] = true;
        }
    }

    return $map;
}

function fetch_stmt_all(mysqli_stmt $stmt): array {
    if (method_exists($stmt, 'get_result')) {
        try {
            $result = $stmt->get_result();
            if ($result instanceof mysqli_result) {
                if (method_exists($result, 'fetch_all')) {
                    return $result->fetch_all(MYSQLI_ASSOC);
                }

                $rows = [];
                while ($row = $result->fetch_assoc()) {
                    $rows[] = $row;
                }
                return $rows;
            }
        } catch (Throwable $e) {
            error_log('Admin Public Donations - get_result() fetch failed: ' . $e->getMessage());
        }
    }

    try {
        $meta = $stmt->result_metadata();
        if ($meta === false) {
            return [];
        }

        $row = [];
        $bind = [];
        $fields = [];
        while ($field = $meta->fetch_field()) {
            $fieldName = $field->name;
            $fields[] = $fieldName;
            $row[$fieldName] = null;
            $bind[] = &$row[$fieldName];
        }
        $meta->close();

        call_user_func_array([$stmt, 'bind_result'], $bind);
        $rows = [];
        while ($stmt->fetch()) {
            $rowCopy = [];
            foreach ($fields as $fieldName) {
                $rowCopy[$fieldName] = $row[$fieldName];
            }
            $rows[] = $rowCopy;
        }

        return $rows;
    } catch (Throwable $e) {
        error_log('Admin Public Donations - fallback fetch failed: ' . $e->getMessage());
        return [];
    }
}

function fetch_stmt_assoc(mysqli_stmt $stmt): ?array {
    $rows = fetch_stmt_all($stmt);
    if ($rows === []) {
        return null;
    }

    return $rows[0];
}

$normalizeRequestPhone = static function (string $rawPhone): string {
    $clean = preg_replace('/\D/', '', trim($rawPhone));
    return $clean === null ? '' : $clean;
};

$buildPhoneLookupList = static function (string $normalizedPhone): array {
    $clean = preg_replace('/\D/', '', $normalizedPhone) ?: '';
    if ($clean === '') {
        return [];
    }

    $candidates = [$clean];

    if (strlen($clean) > 1 && substr($clean, 0, 1) !== '+') {
        if (substr($clean, 0, 2) === '44') {
            $ukMobile = '0' . substr($clean, 2);
            $candidates[] = $ukMobile;
        }

        if (substr($clean, 0, 2) === '07') {
            $candidates[] = '44' . substr($clean, 1);
        }

        if (substr($clean, 0, 1) === '7') {
            $candidates[] = '0' . $clean;
            $candidates[] = '44' . $clean;
        }
    }

    if (strlen($clean) > 2 && substr($clean, 0, 2) === '44') {
        $candidates[] = '0' . substr($clean, 2);
    }
    if (strlen($clean) > 1 && substr($clean, 0, 1) === '0') {
        $candidates[] = '44' . substr($clean, 1);
    }

    return array_values(array_unique($candidates));
};

$buildPhoneMatchConditions = static function (array $phoneColumns, string $cleanColumn, array $lookupPhones): array {
    $conditions = [];
    $types = '';
    $values = [];

    foreach ($lookupPhones as $lookupPhone) {
        foreach ($phoneColumns as $phoneColumn) {
            $conditions[] = sprintf($cleanColumn, '`' . $phoneColumn . '`') . ' = ?';
            $values[] = $lookupPhone;
            $types .= 's';

            if (strlen($lookupPhone) >= 10) {
                $conditions[] = 'RIGHT(' . sprintf($cleanColumn, '`' . $phoneColumn . '`') . ', 10) = RIGHT(?, 10)';
                $values[] = $lookupPhone;
                $types .= 's';
            }
        }
    }

    return [$conditions, $types, $values];
};

$allowedStatuses = ['new', 'contacted', 'resolved', 'spam'];
$statusLabels = [
    'new' => ['label' => 'New', 'class' => 'bg-primary'],
    'contacted' => ['label' => 'Contacted', 'class' => 'bg-info text-dark'],
    'resolved' => ['label' => 'Resolved', 'class' => 'bg-success'],
    'spam' => ['label' => 'Spam', 'class' => 'bg-secondary'],
];

$requestColumns = [];
$donorColumns = [];
$pledgeColumns = [];
$paymentColumns = [];
if ($dbConnected && $db instanceof mysqli) {
    try {
        $requestColumns = table_column_map($db, 'public_donation_requests');
    } catch (Throwable $e) {
        error_log('Admin Public Donations - Column lookup failed (request table): ' . $e->getMessage());
        $requestColumns = [];
    }
}

if ($dbConnected && $db instanceof mysqli) {
    try {
        $donorColumns = table_column_map($db, 'donors');
    } catch (Throwable $e) {
        error_log('Admin Public Donations - Column lookup failed (donor table): ' . $e->getMessage());
        $donorColumns = [];
    }
}

if ($dbConnected && $db instanceof mysqli) {
    try {
        $pledgeColumns = table_column_map($db, 'pledges');
    } catch (Throwable $e) {
        error_log('Admin Public Donations - Column lookup failed (pledges table): ' . $e->getMessage());
        $pledgeColumns = [];
    }
}

if ($dbConnected && $db instanceof mysqli) {
    try {
        $paymentColumns = table_column_map($db, 'payments');
    } catch (Throwable $e) {
        error_log('Admin Public Donations - Column lookup failed (payments table): ' . $e->getMessage());
        $paymentColumns = [];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $dbConnected && $db instanceof mysqli) {
    try {
        verify_csrf();

        $action = (string)($_POST['action'] ?? '');
        if ($action === 'update_status') {
            $requestId = (int)($_POST['request_id'] ?? 0);
            $status = (string)($_POST['status'] ?? '');

            if ($requestId <= 0 || !in_array($status, $allowedStatuses, true)) {
                $isError = true;
                $actionMsg = 'Please provide a valid request and status.';
            } else {
                $adminId = (int)($current_user['id'] ?? 0);
                $updateParts = ['status = ?'];
                if (isset($requestColumns['updated_at'])) {
                    $updateParts[] = 'updated_at = NOW()';
                }
                $updateValues = [$status];
                $updateTypes = 's';

                if (isset($requestColumns['updated_by_user_id'])) {
                    $updateParts[] = 'updated_by_user_id = ?';
                    $updateValues[] = $adminId;
                    $updateTypes .= 'i';
                }

                $updateSql = 'UPDATE public_donation_requests SET ' . implode(', ', $updateParts);
                if (isset($requestColumns['updated_at']) && strpos($updateSql, 'updated_at = NOW()') === false) {
                    $updateSql .= ', updated_at = NOW()';
                }
                $updateSql .= ' WHERE id = ?';
                $updateValues[] = $requestId;
                $updateTypes .= 'i';

                $stmt = $db->prepare($updateSql);
                if (!$stmt) {
                    $isError = true;
                    $actionMsg = 'Database error while updating status.';
                } else {
                    bind_query_params($stmt, $updateTypes, $updateValues);
                    $stmt->execute();

                    if ($stmt->affected_rows >= 0) {
                        $actionMsg = 'Status updated successfully.';
                    } else {
                        $isError = true;
                        $actionMsg = 'Unable to update status right now. Please retry.';
                    }
                    $stmt->close();

                    if (!$isError) {
                        $returnParams = [];
                        $returnKeys = ['search', 'filter_status', 'date_from', 'date_to', 'sort_by', 'sort_order', 'page', 'per_page'];
                        foreach ($returnKeys as $returnKey) {
                            if (!empty($_POST[$returnKey])) {
                                $returnParams[$returnKey] = (string)$_POST[$returnKey];
                            }
                        }
                        $returnParams['msg'] = (string)$actionMsg;
                        header('Location: public-donations.php' . ($returnParams ? ('?' . http_build_query($returnParams)) : ''));
                        exit;
                    }
                }
            }
        }
    } catch (Throwable $e) {
        $isError = true;
        $actionMsg = 'Unable to update status right now.';
        error_log('Admin Public Donations - POST update failed: ' . $e->getMessage());
    }
}

$requestsTableExists = false;
if ($dbConnected && $db instanceof mysqli) {
    try {
        $tableCheck = $db->query("SHOW TABLES LIKE 'public_donation_requests'");
        $requestsTableExists = (bool)($tableCheck && $tableCheck->num_rows > 0);
    } catch (Throwable $e) {
        $isError = true;
            $actionMsg = 'Unable to verify the request table status.';
            if ($showDebug) {
                $actionMsg .= ' ' . $e->getMessage();
            }
            error_log('Admin Public Donations - Failed table check: ' . $e->getMessage());
            $requestsTableExists = false;
        }
}

$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? $_GET['filter_status'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$sortBy = trim((string)($_GET['sort_by'] ?? 'created_at'));
$sortOrder = strtolower((string)($_GET['sort_order'] ?? 'desc'));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 20);
if (!in_array($perPage, [10, 20, 50], true)) {
    $perPage = 20;
}

$sortLabels = [];
if (isset($requestColumns['created_at'])) {
    $sortLabels['created_at'] = 'Created';
}
if (isset($requestColumns['updated_at'])) {
    $sortLabels['updated_at'] = 'Updated';
}
if (isset($requestColumns['full_name'])) {
    $sortLabels['full_name'] = 'Name';
}
if (isset($requestColumns['phone_number'])) {
    $sortLabels['phone_number'] = 'Phone';
}
if (isset($requestColumns['status'])) {
    $sortLabels['status'] = 'Status';
}
if (!isset($sortLabels['created_at']) && isset($requestColumns['id'])) {
    $sortLabels['id'] = 'ID';
}
if (empty($sortLabels) && isset($requestColumns['id'])) {
    $sortLabels['id'] = 'ID';
}

if ($sortLabels === []) {
    $sortLabels['1'] = 'Default';
}

if (!isset($sortLabels[$sortBy])) {
    $sortBy = array_key_first($sortLabels) ?: 'created_at';
}
if ($sortOrder !== 'asc' && $sortOrder !== 'desc') {
    $sortOrder = 'desc';
}
$sortExpression = ($sortBy === '1') ? '1' : ('`' . $sortBy . '`');

$filters = ['1 = 1'];
$values = [];
$types = '';

if (isset($requestColumns['status']) && $statusFilter !== '' && in_array($statusFilter, $allowedStatuses, true)) {
    $filters[] = 'status = ?';
    $values[] = $statusFilter;
    $types .= 's';
}

if ($search !== '') {
    $searchableColumns = [];
    foreach (['full_name', 'phone_number', 'message'] as $column) {
        if (isset($requestColumns[$column])) {
            $searchableColumns[] = "{$column} LIKE ?";
        }
    }

    if (!empty($searchableColumns)) {
        $filters[] = '(' . implode(' OR ', $searchableColumns) . ')';
        $pattern = '%' . $search . '%';
        $values = array_merge($values, array_fill(0, count($searchableColumns), $pattern));
        $types .= str_repeat('s', count($searchableColumns));
    }
}

$validatedDateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) ? $dateFrom : '';
$validatedDateTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) ? $dateTo : '';

if (isset($requestColumns['created_at']) && $validatedDateFrom !== '') {
    $filters[] = 'DATE(created_at) >= ?';
    $values[] = $validatedDateFrom;
    $types .= 's';
}
if (isset($requestColumns['created_at']) && $validatedDateTo !== '') {
    $filters[] = 'DATE(created_at) <= ?';
    $values[] = $validatedDateTo;
    $types .= 's';
}

$whereClause = implode(' AND ', $filters);

$totalItems = 0;
$totalPages = 0;
$requestRows = [];

if ($requestsTableExists && $dbConnected && $db instanceof mysqli) {
    try {
        $countSql = "SELECT COUNT(*) AS total_items FROM public_donation_requests WHERE {$whereClause}";
        $countStmt = $db->prepare($countSql);
        if (!$countStmt) {
            $isError = true;
            $actionMsg = 'Failed to build request query.';
            if ($showDebug) {
                $actionMsg .= ' ' . $db->error;
            }
        } else {
            bind_query_params($countStmt, $types, $values);
            $countStmt->execute();
            $countResult = fetch_stmt_assoc($countStmt);
            $totalItems = (int)($countResult['total_items'] ?? 0);
            $countStmt->close();
        }

        $totalPages = (int)ceil($totalItems / $perPage);
        if ($totalPages < 1) {
            $totalPages = 1;
        }
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $offset = max(0, ($page - 1) * $perPage);
        $listSelectColumns = [
            'id' => isset($requestColumns['id']) ? '`id`' : '0 AS id',
            'full_name' => isset($requestColumns['full_name']) ? '`full_name`' : "'' AS full_name",
            'phone_number' => isset($requestColumns['phone_number']) ? '`phone_number`' : "'' AS phone_number",
            'message' => isset($requestColumns['message']) ? '`message`' : 'NULL AS message',
            'status' => isset($requestColumns['status']) ? '`status`' : "'new' AS status",
            'ip_address' => isset($requestColumns['ip_address']) ? '`ip_address`' : 'NULL AS ip_address',
            'user_agent' => isset($requestColumns['user_agent']) ? '`user_agent`' : 'NULL AS user_agent',
            'created_at' => isset($requestColumns['created_at']) ? '`created_at`' : 'NOW() AS created_at',
            'updated_at' => isset($requestColumns['updated_at']) ? '`updated_at`' : 'NOW() AS updated_at',
            'updated_by_user_id' => isset($requestColumns['updated_by_user_id']) ? '`updated_by_user_id`' : 'NULL AS updated_by_user_id',
        ];

        $listSql = '
            SELECT
              ' . implode(",\n              ", $listSelectColumns) . '
            FROM public_donation_requests
            WHERE ' . $whereClause . '
            ORDER BY ' . $sortExpression . ' ' . $sortOrder . '
            LIMIT ? OFFSET ?
        ';

        $listStmt = $db->prepare($listSql);
        if (!$listStmt) {
            $isError = true;
            $actionMsg = 'Failed to load request list.';
            if ($showDebug) {
                $actionMsg .= ' ' . $db->error;
            }
        } else {
            $listValues = $values;
            $listTypes = $types . 'ii';
            $listValues[] = $perPage;
            $listValues[] = $offset;
            bind_query_params($listStmt, $listTypes, $listValues);
            $listStmt->execute();
            $requestRows = fetch_stmt_all($listStmt);
            $listStmt->close();
        }

        if (!empty($requestRows)) {
            $donorMatchCache = [];
            $cleanColumn = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(%s, '+', ''), ' ', ''), '-', ''), '(', ''), ')', ''), '.', '')";
            $donorMatchColumns = [];
            if (isset($donorColumns['phone'])) {
                $donorMatchColumns[] = 'phone';
            }
            if (isset($donorColumns['phone_number'])) {
                $donorMatchColumns[] = 'phone_number';
            }

            $pledgeMatchColumns = [];
            if (isset($pledgeColumns['donor_phone'])) {
                $pledgeMatchColumns[] = 'donor_phone';
            }

            $paymentMatchColumns = [];
            if (isset($paymentColumns['donor_phone'])) {
                $paymentMatchColumns[] = 'donor_phone';
            }

            $donorSelectColumns = ['id', 'name'];
            if (isset($donorColumns['phone'])) {
                $donorSelectColumns[] = 'phone';
            }
            if (isset($donorColumns['phone_number'])) {
                $donorSelectColumns[] = 'phone_number';
            }

            $pledgeSortColumn = isset($pledgeColumns['created_at']) ? 'created_at' : 'id';
            $paymentSortColumn = isset($paymentColumns['created_at']) ? 'created_at' : 'id';

            foreach ($requestRows as &$requestRow) {
                $lookupPhones = $buildPhoneLookupList($normalizeRequestPhone((string)($requestRow['phone_number'] ?? '')));
                $cacheKey = implode('|', $lookupPhones);

                if ($cacheKey === '') {
                    $requestRow['donor_match'] = null;
                    continue;
                }

                if (array_key_exists($cacheKey, $donorMatchCache)) {
                    $requestRow['donor_match'] = $donorMatchCache[$cacheKey];
                    continue;
                }

                [$directConditions, $directMatchTypes, $directMatchValues] = $buildPhoneMatchConditions($donorMatchColumns, $cleanColumn, $lookupPhones);
                [$pledgeConditions, $pledgeMatchTypes, $pledgeMatchValues] = $buildPhoneMatchConditions($pledgeMatchColumns, $cleanColumn, $lookupPhones);
                [$paymentConditions, $paymentMatchTypes, $paymentMatchValues] = $buildPhoneMatchConditions($paymentMatchColumns, $cleanColumn, $lookupPhones);

                $donorMatch = null;

                $hasDonorTableMatch = !empty($directConditions) && isset($donorColumns['id']) && isset($donorColumns['name']);
                if ($hasDonorTableMatch) {
                    $matchSql = 'SELECT ' . implode(', ', $donorSelectColumns) . ' FROM donors WHERE ' . implode(' OR ', $directConditions) . ' LIMIT 1';
                } else {
                    $matchSql = '';
                }

                try {
                    if ($matchSql !== '') {
                        $matchStmt = $db->prepare($matchSql);
                        if (!$matchStmt) {
                            $requestRow['donor_match'] = null;
                            $donorMatchCache[$cacheKey] = null;
                            continue;
                        }

                        $matchBind = [$directMatchTypes];
                        foreach ($directMatchValues as $index => $value) {
                            $matchBind[] = &$directMatchValues[$index];
                        }
                        call_user_func_array([$matchStmt, 'bind_param'], $matchBind);
                        $matchStmt->execute();
                        $donorMatch = fetch_stmt_assoc($matchStmt);
                        $matchStmt->close();
                    }

                    if ($donorMatch === null && (isset($pledgeColumns['donor_id']) || isset($paymentColumns['donor_id']))) {
                        $relatedUnionSqlParts = [];
                        $relatedTypes = '';
                        $relatedValues = [];

                        if (!empty($pledgeConditions) && isset($pledgeColumns['donor_id']) && !empty($lookupPhones)) {
                            $relatedUnionSqlParts[] = '
                                SELECT donor_id, ' . $pledgeSortColumn . ' AS matched_at
                                FROM pledges
                                WHERE donor_id IS NOT NULL
                                  AND (' . implode(' OR ', $pledgeConditions) . ')
                            ';
                            $relatedTypes .= $pledgeMatchTypes;
                            foreach ($pledgeMatchValues as $value) {
                                $relatedValues[] = $value;
                            }
                        }

                        if (!empty($paymentConditions) && isset($paymentColumns['donor_id']) && !empty($lookupPhones)) {
                            $relatedUnionSqlParts[] = '
                                SELECT donor_id, ' . $paymentSortColumn . ' AS matched_at
                                FROM payments
                                WHERE donor_id IS NOT NULL
                                  AND (' . implode(' OR ', $paymentConditions) . ')
                            ';
                            $relatedTypes .= $paymentMatchTypes;
                            foreach ($paymentMatchValues as $value) {
                                $relatedValues[] = $value;
                            }
                        }

                        if (!empty($relatedUnionSqlParts)) {
                            $relatedDonorSql = '
                                SELECT donor_id
                                FROM (
                                    ' . implode(' UNION ALL ', $relatedUnionSqlParts) . '
                                ) AS donor_candidates
                                ORDER BY matched_at DESC
                                LIMIT 1
                            ';
                            $relatedStmt = $db->prepare($relatedDonorSql);
                            if ($relatedStmt) {
                                $relatedBind = [$relatedTypes];
                                foreach ($relatedValues as $idx => $value) {
                                    $relatedBind[] = &$relatedValues[$idx];
                                }
                                call_user_func_array([$relatedStmt, 'bind_param'], $relatedBind);
                                $relatedStmt->execute();
                                $relatedDonorRow = fetch_stmt_assoc($relatedStmt);
                                $relatedStmt->close();

                                $relatedDonorId = (int)($relatedDonorRow['donor_id'] ?? 0);
                                if ($relatedDonorId > 0 && isset($donorColumns['id']) && !empty($donorSelectColumns)) {
                                    $donorByIdSql = 'SELECT ' . implode(', ', $donorSelectColumns) . ' FROM donors WHERE id = ? LIMIT 1';
                                    $donorByIdStmt = $db->prepare($donorByIdSql);
                                    if ($donorByIdStmt) {
                                        $donorId = $relatedDonorId;
                                        $donorByIdStmt->bind_param('i', $donorId);
                                        $donorByIdStmt->execute();
                                        $donorMatch = fetch_stmt_assoc($donorByIdStmt);
                                        $donorByIdStmt->close();
                                        $donorMatch = $donorMatch ?: null;
                                    }
                                }
                            }
                        }
                    }

                    $requestRow['donor_match'] = $donorMatch;
                    $donorMatchCache[$cacheKey] = $donorMatch;
                } catch (Throwable $e) {
                    error_log('Admin Public Donations - Donor match failed: ' . $e->getMessage());
                    $requestRow['donor_match'] = null;
                    $donorMatchCache[$cacheKey] = null;
                }
            }
            unset($requestRow);
        }
    } catch (Throwable $e) {
        $isError = true;
        $actionMsg = 'Unable to load donation request records.';
        if ($showDebug) {
            $actionMsg .= ' ' . $e->getMessage();
        }
        error_log('Admin Public Donations - Query failed: ' . $e->getMessage());
        $totalItems = 0;
        $totalPages = 1;
        $requestRows = [];
    }
}

$persistedParams = [
    'search' => $search,
    'status' => $statusFilter,
    'date_from' => $validatedDateFrom,
    'date_to' => $validatedDateTo,
    'sort_by' => $sortBy,
    'sort_order' => $sortOrder,
    'per_page' => $perPage,
];

$buildUrl = static function(string $path, int $targetPage, array $baseParams) use ($persistedParams): string {
    $params = $persistedParams;
    $params['page'] = (string)$targetPage;

    $filtered = [];
    foreach ($params as $key => $value) {
        if ($value !== '' && $value !== null) {
            $filtered[$key] = $value;
        }
    }

    if (!empty($baseParams)) {
        foreach ($baseParams as $key => $value) {
            if ($value !== '' && $value !== null) {
                $filtered[$key] = $value;
            } else {
                unset($filtered[$key]);
            }
        }
    }

    return $path . (empty($filtered) ? '' : ('?' . http_build_query($filtered)));
};

if ($actionMsg === '' && isset($_GET['msg']) && trim((string)$_GET['msg']) !== '') {
    $actionMsg = (string)$_GET['msg'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Public Donation Requests - Fundraising Admin</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css?v=<?php echo @filemtime(__DIR__ . '/../assets/admin.css'); ?>">
    <style>
        /* Page Hero Header */
        .page-hero {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 60%, var(--primary-light) 100%);
            border-radius: 1rem;
            padding: 1.75rem 2rem;
            color: var(--white);
            margin-bottom: 1.5rem;
            position: relative;
            overflow: hidden;
        }
        .page-hero::before {
            content: '';
            position: absolute;
            top: -40%;
            right: -10%;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }
        .page-hero::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: 20%;
            width: 200px;
            height: 200px;
            background: radial-gradient(circle, rgba(226,202,24,0.1) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }
        .page-hero h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0 0 0.25rem;
            position: relative;
            z-index: 1;
        }
        .page-hero p {
            margin: 0;
            opacity: 0.85;
            font-size: 0.9rem;
            position: relative;
            z-index: 1;
        }
        .page-hero .hero-actions {
            position: relative;
            z-index: 1;
        }
        .page-hero .btn-hero {
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.3);
            color: var(--white);
            padding: 0.5rem 1.25rem;
            border-radius: 0.5rem;
            font-weight: 600;
            font-size: 0.85rem;
            transition: all var(--transition-fast);
            text-decoration: none;
        }
        .page-hero .btn-hero:hover {
            background: rgba(255,255,255,0.25);
            border-color: rgba(255,255,255,0.5);
            transform: translateY(-1px);
            color: var(--white);
        }

        /* Stats Row */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .stat-mini {
            background: var(--white);
            border: 1px solid var(--gray-200);
            border-radius: 0.75rem;
            padding: 1rem 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.875rem;
            transition: all var(--transition);
            cursor: default;
        }
        .stat-mini:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        .stat-mini .stat-icon {
            width: 42px;
            height: 42px;
            border-radius: 0.625rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
        }
        .stat-mini .stat-icon.blue { background: rgba(59,130,246,0.1); color: var(--info); }
        .stat-mini .stat-icon.green { background: rgba(16,185,129,0.1); color: var(--success); }
        .stat-mini .stat-icon.amber { background: rgba(245,158,11,0.1); color: var(--warning); }
        .stat-mini .stat-icon.red { background: rgba(239,68,68,0.1); color: var(--danger); }
        .stat-mini .stat-icon.slate { background: rgba(107,114,128,0.1); color: var(--gray-500); }
        .stat-mini .stat-value {
            font-size: 1.35rem;
            font-weight: 700;
            color: var(--gray-900);
            line-height: 1;
        }
        .stat-mini .stat-label {
            font-size: 0.75rem;
            color: var(--gray-500);
            font-weight: 500;
            margin-top: 0.125rem;
        }

        /* Enhanced Filter Card */
        .filter-card {
            background: var(--white);
            border: 1px solid var(--gray-200);
            border-radius: 0.75rem;
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.5rem;
        }
        .filter-card .form-label {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--gray-500);
            margin-bottom: 0.375rem;
        }
        .filter-card .form-control,
        .filter-card .form-select {
            border-radius: 0.5rem;
            border-color: var(--gray-200);
            font-size: 0.875rem;
            transition: all var(--transition-fast);
        }
        .filter-card .form-control:focus,
        .filter-card .form-select:focus {
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(14,165,233,0.12);
        }
        .btn-filter-primary {
            background: var(--primary);
            color: var(--white);
            border: none;
            padding: 0.5rem 1.5rem;
            border-radius: 0.5rem;
            font-weight: 600;
            font-size: 0.85rem;
            transition: all var(--transition-fast);
        }
        .btn-filter-primary:hover {
            background: var(--primary-dark);
            color: var(--white);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(10,98,134,0.25);
        }
        .btn-filter-reset {
            background: var(--gray-100);
            color: var(--gray-600);
            border: 1px solid var(--gray-200);
            padding: 0.5rem 1.5rem;
            border-radius: 0.5rem;
            font-weight: 500;
            font-size: 0.85rem;
            text-decoration: none;
            transition: all var(--transition-fast);
        }
        .btn-filter-reset:hover {
            background: var(--gray-200);
            color: var(--gray-700);
        }

        /* Enhanced Data Table Card */
        .data-card {
            background: var(--white);
            border: 1px solid var(--gray-200);
            border-radius: 0.75rem;
            overflow: hidden;
        }
        .data-card-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--gray-100);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 0.75rem;
        }
        .data-card-header .items-count {
            font-size: 0.85rem;
            color: var(--gray-500);
        }
        .data-card-header .items-count strong {
            color: var(--gray-900);
            font-weight: 700;
        }
        .per-page-group {
            display: flex;
            align-items: center;
            gap: 0.375rem;
        }
        .per-page-group .per-page-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 30px;
            border-radius: 0.375rem;
            font-size: 0.8rem;
            font-weight: 600;
            text-decoration: none;
            transition: all var(--transition-fast);
            border: 1px solid var(--gray-200);
            color: var(--gray-500);
            background: var(--white);
        }
        .per-page-group .per-page-btn:hover {
            border-color: var(--primary-light);
            color: var(--primary);
            background: rgba(14,165,233,0.04);
        }
        .per-page-group .per-page-btn.active {
            background: var(--primary);
            color: var(--white);
            border-color: var(--primary);
        }

        /* Table Enhancements */
        .table-wrapper {
            overflow-x: auto;
        }
        .enhanced-table {
            font-size: 0.875rem;
        }
        .enhanced-table thead th {
            background: var(--gray-50);
            border-bottom: 2px solid var(--gray-200);
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--gray-500);
            padding: 0.75rem 1rem;
            white-space: nowrap;
        }
        .enhanced-table tbody td {
            padding: 0.875rem 1rem;
            vertical-align: middle;
            border-bottom: 1px solid var(--gray-100);
        }
        .enhanced-table tbody tr {
            transition: background-color var(--transition-fast);
        }
        .enhanced-table tbody tr:hover {
            background-color: rgba(14,165,233,0.03);
        }
        .enhanced-table tbody tr:last-child td {
            border-bottom: none;
        }

        /* Row ID Badge */
        .row-id {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--gray-100);
            color: var(--gray-600);
            font-weight: 700;
            font-size: 0.75rem;
            padding: 0.25rem 0.625rem;
            border-radius: 0.375rem;
            font-family: 'SF Mono', 'Fira Code', monospace;
        }

        /* Donor Name Cell */
        .donor-name {
            font-weight: 600;
            color: var(--gray-800);
        }

        /* Phone Cell */
        .phone-cell {
            font-family: 'SF Mono', 'Fira Code', ui-monospace, monospace;
            font-size: 0.8rem;
            color: var(--gray-600);
            letter-spacing: 0.02em;
        }

        /* Message Cell */
        .request-message {
            max-width: 280px;
        }
        .message-text {
            font-size: 0.825rem;
            color: var(--gray-600);
            line-height: 1.45;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            cursor: pointer;
        }
        .message-text:hover {
            color: var(--gray-800);
        }
        .text-wrap-anywhere {
            overflow-wrap: anywhere;
        }

        /* Donor Match Cell */
        .donor-match-found {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .donor-match-found .match-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--success), #059669);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-size: 0.7rem;
            flex-shrink: 0;
        }
        .donor-match-found .match-info a {
            font-weight: 600;
            color: var(--primary);
            text-decoration: none;
            font-size: 0.85rem;
        }
        .donor-match-found .match-info a:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }
        .donor-match-found .match-phone {
            font-size: 0.7rem;
            color: var(--gray-400);
            font-family: 'SF Mono', 'Fira Code', monospace;
        }
        .donor-no-match {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            color: var(--gray-400);
            font-size: 0.8rem;
        }

        /* Enhanced Status Pill */
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            border-radius: 999px;
            padding: 0.3rem 0.75rem;
            font-size: 0.72rem;
            font-weight: 600;
            white-space: nowrap;
            letter-spacing: 0.02em;
        }
        .status-pill.status-new {
            background: rgba(59,130,246,0.1);
            color: #2563eb;
        }
        .status-pill.status-contacted {
            background: rgba(14,165,233,0.1);
            color: #0284c7;
        }
        .status-pill.status-resolved {
            background: rgba(16,185,129,0.1);
            color: #059669;
        }
        .status-pill.status-spam {
            background: rgba(107,114,128,0.1);
            color: #6b7280;
        }
        .status-pill .status-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: currentColor;
        }
        .status-pill.status-new .status-dot {
            animation: pulse 2s infinite;
        }

        /* Timestamp Cell */
        .timestamp-cell {
            font-size: 0.78rem;
            line-height: 1.5;
            white-space: nowrap;
        }
        .timestamp-cell .ts-date {
            font-weight: 600;
            color: var(--gray-700);
        }
        .timestamp-cell .ts-time {
            color: var(--gray-400);
            font-size: 0.72rem;
        }
        .timestamp-cell .ts-label {
            font-size: 0.65rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--gray-400);
        }

        /* IP Cell */
        .ip-cell {
            font-family: 'SF Mono', 'Fira Code', monospace;
            font-size: 0.75rem;
            color: var(--gray-500);
        }
        .ip-cell .ua-snippet {
            font-family: 'Inter', sans-serif;
            font-size: 0.68rem;
            color: var(--gray-400);
            max-width: 120px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            display: block;
        }

        /* Action Form */
        .action-form {
            min-width: 200px;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .action-form .form-select {
            font-size: 0.8rem;
            border-radius: 0.375rem;
            padding: 0.35rem 0.5rem;
            border-color: var(--gray-200);
        }
        .action-form .btn-save {
            background: var(--primary);
            color: var(--white);
            border: none;
            font-size: 0.78rem;
            font-weight: 600;
            padding: 0.375rem 0.75rem;
            border-radius: 0.375rem;
            white-space: nowrap;
            transition: all var(--transition-fast);
        }
        .action-form .btn-save:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(10,98,134,0.25);
        }

        /* Enhanced Pagination */
        .pagination-wrapper {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.25rem 1.5rem;
            border-top: 1px solid var(--gray-100);
        }
        .pagination-wrapper .pagination {
            gap: 0.25rem;
            margin: 0;
        }
        .pagination-wrapper .page-link {
            border: 1px solid var(--gray-200);
            border-radius: 0.5rem;
            font-size: 0.8rem;
            font-weight: 600;
            padding: 0.4rem 0.75rem;
            color: var(--gray-600);
            transition: all var(--transition-fast);
        }
        .pagination-wrapper .page-link:hover {
            background: var(--primary);
            border-color: var(--primary);
            color: var(--white);
            transform: translateY(-1px);
        }
        .pagination-wrapper .page-item.active .page-link {
            background: var(--primary);
            border-color: var(--primary);
            color: var(--white);
            box-shadow: 0 2px 8px rgba(10,98,134,0.3);
        }
        .pagination-wrapper .page-item.disabled .page-link {
            color: var(--gray-300);
            background: var(--gray-50);
            border-color: var(--gray-100);
        }

        /* Alert Enhancement */
        .alert-enhanced {
            border: none;
            border-radius: 0.75rem;
            padding: 1rem 1.25rem;
            font-size: 0.875rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .alert-enhanced.alert-success {
            background: rgba(16,185,129,0.08);
            color: #065f46;
            border-left: 4px solid var(--success);
        }
        .alert-enhanced.alert-danger {
            background: rgba(239,68,68,0.08);
            color: #991b1b;
            border-left: 4px solid var(--danger);
        }

        /* Empty State */
        .empty-state {
            padding: 3rem 2rem;
            text-align: center;
        }
        .empty-state .empty-icon {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: var(--gray-100);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: var(--gray-400);
            margin-bottom: 1rem;
        }
        .empty-state .empty-title {
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 0.25rem;
        }
        .empty-state .empty-desc {
            font-size: 0.85rem;
            color: var(--gray-400);
        }

        /* DB/Table Error Cards */
        .error-card {
            background: var(--white);
            border: 1px solid var(--gray-200);
            border-radius: 0.75rem;
            overflow: hidden;
        }
        .error-card .error-body {
            padding: 2rem;
        }
        .error-card .error-alert {
            border: none;
            border-radius: 0.625rem;
        }

        /* Responsive */
        @media (max-width: 767.98px) {
            .page-hero {
                padding: 1.25rem 1.25rem;
                border-radius: 0.75rem;
            }
            .page-hero h1 {
                font-size: 1.2rem;
            }
            .stats-row {
                grid-template-columns: repeat(2, 1fr);
            }
            .filter-card {
                padding: 1rem;
            }
            .data-card-header {
                padding: 0.75rem 1rem;
            }
            .enhanced-table thead th,
            .enhanced-table tbody td {
                padding: 0.625rem 0.75rem;
            }
        }
        @media (max-width: 575.98px) {
            .stats-row {
                grid-template-columns: 1fr 1fr;
                gap: 0.625rem;
            }
            .stat-mini {
                padding: 0.75rem;
            }
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="admin-content">
        <?php include __DIR__ . '/../includes/topbar.php'; ?>

        <main class="main-content">
            <div class="container-fluid">
                <!-- Hero Header -->
                <div class="page-hero animate-fade-in">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                        <div>
                            <h1><i class="fas fa-hand-holding-heart me-2" style="opacity:0.8"></i>Public Donation Requests</h1>
                            <p>Manage and review incoming contact requests from the public donation page.</p>
                        </div>
                        <div class="hero-actions">
                            <a href="index.php" class="btn-hero">
                                <i class="fas fa-arrow-left me-1"></i> Back to Approvals
                            </a>
                        </div>
                    </div>
                </div>

                <?php if ($actionMsg !== ''): ?>
                    <div id="actionMessage" class="alert alert-enhanced <?php echo $isError ? 'alert-danger' : 'alert-success'; ?> alert-dismissible fade show mb-4" role="alert">
                        <i class="fas fa-<?php echo $isError ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
                        <?php echo htmlspecialchars($actionMsg, ENT_QUOTES, 'UTF-8'); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if (!$dbConnected): ?>
                    <div class="error-card">
                        <div class="error-body">
                            <div class="alert alert-danger error-alert mb-0">
                                <div class="d-flex align-items-start gap-3">
                                    <div style="font-size:1.5rem;opacity:0.7"><i class="fas fa-database"></i></div>
                                    <div>
                                        <h5 class="mb-1 fw-bold">Database Connection Error</h5>
                                        <p class="mb-0"><?php echo htmlspecialchars($actionMsg, ENT_QUOTES, 'UTF-8'); ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php elseif (!$requestsTableExists): ?>
                    <div class="error-card">
                        <div class="error-body">
                            <div class="alert alert-warning error-alert mb-3">
                                <div class="d-flex align-items-start gap-3">
                                    <div style="font-size:1.5rem;opacity:0.7"><i class="fas fa-table"></i></div>
                                    <div>
                                        <h5 class="mb-1 fw-bold">Database table missing</h5>
                                        <p class="mb-1">The table <strong>public_donation_requests</strong> is not present.</p>
                                        <p class="mb-0">Run the SQL below in phpMyAdmin first:</p>
                                    </div>
                                </div>
                            </div>
                            <pre class="bg-light p-3 rounded text-wrap" style="font-size:0.8rem">CREATE TABLE `public_donation_requests` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `full_name` varchar(120) NOT NULL,
  `phone_number` varchar(40) NOT NULL,
  `message` text NULL,
  `status` enum('new','contacted','resolved','spam') NOT NULL DEFAULT 'new',
  `source_page` varchar(255) NULL,
  `source_url` varchar(500) NULL,
  `referrer_url` varchar(500) NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` varchar(512) NULL,
  `updated_by_user_id` int unsigned NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status_created_at` (`status`,`created_at`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_phone_number` (`phone_number`),
  KEY `idx_ip_address` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;</pre>
                        </div>
                    </div>
                <?php else: ?>

                    <?php
                    // Compute status counts for stats row
                    $statusCounts = ['new' => 0, 'contacted' => 0, 'resolved' => 0, 'spam' => 0];
                    if ($dbConnected && $db instanceof mysqli && isset($requestColumns['status'])) {
                        try {
                            $countsResult = $db->query("SELECT status, COUNT(*) AS cnt FROM public_donation_requests GROUP BY status");
                            if ($countsResult) {
                                while ($cRow = $countsResult->fetch_assoc()) {
                                    $s = (string)($cRow['status'] ?? '');
                                    if (isset($statusCounts[$s])) {
                                        $statusCounts[$s] = (int)$cRow['cnt'];
                                    }
                                }
                            }
                        } catch (Throwable $e) {
                            // Silently ignore stats count errors
                        }
                    }
                    $statusCountTotal = array_sum($statusCounts);
                    ?>

                    <!-- Stats Row -->
                    <div class="stats-row animate-fade-in" style="animation-delay:0.1s">
                        <div class="stat-mini">
                            <div class="stat-icon blue"><i class="fas fa-inbox"></i></div>
                            <div>
                                <div class="stat-value"><?php echo number_format($statusCountTotal); ?></div>
                                <div class="stat-label">Total Requests</div>
                            </div>
                        </div>
                        <div class="stat-mini">
                            <div class="stat-icon amber"><i class="fas fa-star"></i></div>
                            <div>
                                <div class="stat-value"><?php echo number_format($statusCounts['new']); ?></div>
                                <div class="stat-label">New</div>
                            </div>
                        </div>
                        <div class="stat-mini">
                            <div class="stat-icon blue"><i class="fas fa-phone"></i></div>
                            <div>
                                <div class="stat-value"><?php echo number_format($statusCounts['contacted']); ?></div>
                                <div class="stat-label">Contacted</div>
                            </div>
                        </div>
                        <div class="stat-mini">
                            <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                            <div>
                                <div class="stat-value"><?php echo number_format($statusCounts['resolved']); ?></div>
                                <div class="stat-label">Resolved</div>
                            </div>
                        </div>
                        <div class="stat-mini">
                            <div class="stat-icon slate"><i class="fas fa-ban"></i></div>
                            <div>
                                <div class="stat-value"><?php echo number_format($statusCounts['spam']); ?></div>
                                <div class="stat-label">Spam</div>
                            </div>
                        </div>
                    </div>

                    <!-- Filter Card -->
                    <div class="filter-card animate-fade-in" style="animation-delay:0.15s">
                        <form method="GET" class="row g-2 align-items-end">
                            <div class="col-lg-3 col-md-6">
                                <label class="form-label">Search</label>
                                <input type="text" class="form-control form-control-sm" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Name, phone, or message...">
                            </div>
                            <div class="col-lg-2 col-md-3">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select form-select-sm">
                                    <option value="">All Statuses</option>
                                        <?php foreach ($allowedStatuses as $status): ?>
                                        <option value="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $statusFilter === $status ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($statusLabels[$status]['label'], ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-lg-2 col-md-3">
                                <label class="form-label">From Date</label>
                                <input type="date" class="form-control form-control-sm" name="date_from" value="<?php echo htmlspecialchars($validatedDateFrom, ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="col-lg-2 col-md-3">
                                <label class="form-label">To Date</label>
                                <input type="date" class="form-control form-control-sm" name="date_to" value="<?php echo htmlspecialchars($validatedDateTo, ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="col-lg-3 col-md-6">
                                <label class="form-label">Sort By</label>
                                <div class="input-group input-group-sm">
                                    <select name="sort_by" class="form-select form-select-sm">
                                        <?php foreach ($sortLabels as $key => $label): ?>
                                            <option value="<?php echo $key; ?>" <?php echo $sortBy === $key ? 'selected' : ''; ?>><?php echo $label; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <select name="sort_order" class="form-select form-select-sm">
                                        <option value="desc" <?php echo $sortOrder === 'desc' ? 'selected' : ''; ?>>Desc</option>
                                        <option value="asc" <?php echo $sortOrder === 'asc' ? 'selected' : ''; ?>>Asc</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-12 d-flex gap-2 mt-3">
                                <button class="btn-filter-primary" type="submit"><i class="fas fa-search me-1"></i> Apply Filters</button>
                                <a href="public-donations.php" class="btn-filter-reset"><i class="fas fa-undo me-1"></i> Reset</a>
                            </div>
                        </form>
                    </div>

                    <!-- Data Table Card -->
                    <div class="data-card animate-fade-in" style="animation-delay:0.2s">
                        <div class="data-card-header">
                            <div class="items-count">
                                Showing <strong><?php echo number_format(count($requestRows)); ?></strong> of <strong><?php echo number_format($totalItems); ?></strong> requests
                                <?php if ($statusFilter !== ''): ?>
                                    &middot; Filtered by <span class="status-pill status-<?php echo htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8'); ?>" style="font-size:0.68rem;padding:0.2rem 0.5rem"><span class="status-dot"></span><?php echo htmlspecialchars($statusLabels[$statusFilter]['label'] ?? ucfirst($statusFilter), ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="per-page-group">
                                <span style="font-size:0.75rem;color:var(--gray-400);margin-right:0.25rem">Per page:</span>
                                <?php
                                $perPageMap = [10, 20, 50];
                                foreach ($perPageMap as $itemCount):
                                    $ppUrl = ($itemCount === $perPage) ? '#' : $buildUrl('public-donations.php', 1, ['per_page' => $itemCount]);
                                ?>
                                    <?php if ($itemCount === $perPage): ?>
                                        <span class="per-page-btn active"><?php echo $itemCount; ?></span>
                                    <?php else: ?>
                                        <a href="<?php echo htmlspecialchars($ppUrl, ENT_QUOTES, 'UTF-8'); ?>" class="per-page-btn"><?php echo $itemCount; ?></a>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="table-responsive table-wrapper">
                            <table class="table enhanced-table mb-0">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Donor</th>
                                        <th>Phone</th>
                                        <th>Message</th>
                                        <th>Existing Donor</th>
                                        <th>Status</th>
                                        <th>Submitted</th>
                                        <th>IP / Agent</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($requestRows)): ?>
                                        <tr>
                                            <td colspan="9">
                                                <div class="empty-state">
                                                    <div class="empty-icon"><i class="fas fa-inbox"></i></div>
                                                    <div class="empty-title">No requests found</div>
                                                    <div class="empty-desc">Try adjusting your filters or check back later.</div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($requestRows as $req): ?>
                                            <tr>
                                                <td><span class="row-id">#<?php echo (int)$req['id']; ?></span></td>
                                                <td><div class="donor-name"><?php echo htmlspecialchars((string)$req['full_name'], ENT_QUOTES, 'UTF-8'); ?></div></td>
                                                <td><span class="phone-cell"><?php echo htmlspecialchars((string)$req['phone_number'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                                <td>
                                                    <div class="request-message text-wrap-anywhere">
                                                        <?php
                                                        $msg = (string)($req['message'] ?? '');
                                                        $shortMsg = $msg === '' ? '' : mb_strimwidth(htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'), 0, 140, '...');
                                                        ?>
                                                        <?php if ($msg === ''): ?>
                                                            <span class="text-muted" style="font-size:0.8rem"><i class="fas fa-minus me-1"></i>No message</span>
                                                        <?php else: ?>
                                                            <span class="message-text" title="<?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?>"><?php echo $shortMsg; ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php $donorMatch = $req['donor_match'] ?? null; ?>
                                                    <?php if (!empty($donorMatch) && !empty($donorMatch['id'])): ?>
                                                        <div class="donor-match-found">
                                                            <div class="match-avatar"><i class="fas fa-user-check"></i></div>
                                                            <div class="match-info">
                                                                <a href="../donor-management/view-donor.php?id=<?php echo (int)$donorMatch['id']; ?>"><?php echo htmlspecialchars((string)($donorMatch['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></a>
                                                                <div class="match-phone"><?php echo htmlspecialchars((string)($donorMatch['phone'] ?: $donorMatch['phone_number']), ENT_QUOTES, 'UTF-8'); ?></div>
                                                            </div>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="donor-no-match"><i class="fas fa-user-xmark"></i> No match</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php $status = (string)($req['status'] ?? 'new'); ?>
                                                    <span class="status-pill status-<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>">
                                                        <span class="status-dot"></span>
                                                        <?php echo htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8'); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="timestamp-cell">
                                                        <?php $createdTs = strtotime((string)$req['created_at']); $updatedTs = strtotime((string)$req['updated_at']); ?>
                                                        <span class="ts-date"><?php echo date('d M Y', $createdTs); ?></span>
                                                        <span class="ts-time"><?php echo date('H:i', $createdTs); ?></span><br>
                                                        <span class="ts-label">Updated:</span>
                                                        <span class="ts-time"><?php echo date('d M Y H:i', $updatedTs); ?></span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="ip-cell">
                                                        <?php echo htmlspecialchars((string)$req['ip_address'], ENT_QUOTES, 'UTF-8'); ?>
                                                        <?php $agent = (string)($req['user_agent'] ?? ''); ?>
                                                        <?php if ($agent !== ''): ?>
                                                            <span class="ua-snippet" title="<?php echo htmlspecialchars($agent, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(substr($agent, 0, 35), ENT_QUOTES, 'UTF-8'); ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <form method="POST" class="action-form">
                                                        <?php echo csrf_input(); ?>
                                                        <input type="hidden" name="action" value="update_status">
                                                        <input type="hidden" name="request_id" value="<?php echo (int)$req['id']; ?>">
                                                        <input type="hidden" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
                                                        <input type="hidden" name="filter_status" value="<?php echo htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8'); ?>">
                                                        <input type="hidden" name="date_from" value="<?php echo htmlspecialchars($validatedDateFrom, ENT_QUOTES, 'UTF-8'); ?>">
                                                        <input type="hidden" name="date_to" value="<?php echo htmlspecialchars($validatedDateTo, ENT_QUOTES, 'UTF-8'); ?>">
                                                        <input type="hidden" name="sort_by" value="<?php echo htmlspecialchars($sortBy, ENT_QUOTES, 'UTF-8'); ?>">
                                                        <input type="hidden" name="sort_order" value="<?php echo htmlspecialchars($sortOrder, ENT_QUOTES, 'UTF-8'); ?>">
                                                        <input type="hidden" name="page" value="<?php echo (int)$page; ?>">
                                                        <input type="hidden" name="per_page" value="<?php echo (int)$perPage; ?>">
                                                        <select name="status" class="form-select form-select-sm">
                                                            <?php foreach ($allowedStatuses as $statusOption): ?>
                                                                <option value="<?php echo htmlspecialchars($statusOption, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $status === $statusOption ? 'selected' : ''; ?>>
                                                                    <?php echo htmlspecialchars(ucfirst($statusOption), ENT_QUOTES, 'UTF-8'); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <button class="btn btn-save" type="submit"><i class="fas fa-check me-1"></i>Save</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($totalPages > 1): ?>
                            <div class="pagination-wrapper">
                                <nav aria-label="Public request pagination">
                                    <ul class="pagination">
                                        <?php if ($page > 1): ?>
                                            <li class="page-item"><a class="page-link" href="<?php echo htmlspecialchars($buildUrl('public-donations.php', 1, []), ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-angles-left"></i></a></li>
                                            <li class="page-item"><a class="page-link" href="<?php echo htmlspecialchars($buildUrl('public-donations.php', $page - 1, []), ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-angle-left"></i></a></li>
                                        <?php else: ?>
                                            <li class="page-item disabled"><span class="page-link"><i class="fas fa-angles-left"></i></span></li>
                                            <li class="page-item disabled"><span class="page-link"><i class="fas fa-angle-left"></i></span></li>
                                        <?php endif; ?>

                                        <?php $start = max(1, $page - 2); $end = min($totalPages, $page + 2); for ($i = $start; $i <= $end; $i++): ?>
                                            <?php if ($i === $page): ?>
                                                <li class="page-item active"><span class="page-link"><?php echo $i; ?></span></li>
                                            <?php else: ?>
                                                <li class="page-item"><a class="page-link" href="<?php echo htmlspecialchars($buildUrl('public-donations.php', $i, []), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $i; ?></a></li>
                                            <?php endif; ?>
                                        <?php endfor; ?>

                                        <?php if ($page < $totalPages): ?>
                                            <li class="page-item"><a class="page-link" href="<?php echo htmlspecialchars($buildUrl('public-donations.php', $page + 1, []), ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-angle-right"></i></a></li>
                                            <li class="page-item"><a class="page-link" href="<?php echo htmlspecialchars($buildUrl('public-donations.php', $totalPages, []), ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-angles-right"></i></a></li>
                                        <?php else: ?>
                                            <li class="page-item disabled"><span class="page-link"><i class="fas fa-angle-right"></i></span></li>
                                            <li class="page-item disabled"><span class="page-link"><i class="fas fa-angles-right"></i></span></li>
                                        <?php endif; ?>
                                    </ul>
                                </nav>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js?v=<?php echo @filemtime(__DIR__ . '/../assets/admin.js'); ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Expand/collapse message on click
    document.querySelectorAll('.message-text').forEach(function(el) {
        el.addEventListener('click', function() {
            var full = this.getAttribute('title');
            if (full) {
                if (this.dataset.expanded === '1') {
                    this.textContent = this.dataset.short;
                    this.dataset.expanded = '0';
                    this.style.webkitLineClamp = '2';
                } else {
                    this.dataset.short = this.textContent;
                    this.textContent = full;
                    this.dataset.expanded = '1';
                    this.style.webkitLineClamp = 'unset';
                }
            }
        });
    });

    // Auto-dismiss success alerts after 4 seconds
    var alertEl = document.getElementById('actionMessage');
    if (alertEl && alertEl.classList.contains('alert-success')) {
        setTimeout(function() {
            var bsAlert = bootstrap.Alert.getOrCreateInstance(alertEl);
            if (bsAlert) bsAlert.close();
        }, 4000);
    }
});
</script>
</body>
</html>
