<?php
declare(strict_types=1);

require_once '../../../config/db.php';
require_once '../../../shared/auth.php';
require_once '../../../shared/DonorCampaignGroups.php';

header('Content-Type: application/json');

require_login();
require_admin();

/**
 * @param list<mixed> $params
 */
function dvc_query(mysqli $db, string $sql, string $types, array $params): mysqli_result
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

    $hasDataSource = false;
    $dsCheck = $db->query("SHOW COLUMNS FROM donors LIKE 'data_source'");
    if ($dsCheck && $dsCheck->num_rows > 0) {
        $hasDataSource = true;
    }

    $groupExpr = DonorCampaignGroups::sqlCase('d');
    $sourceSelect = $hasDataSource ? 'd.data_source' : "'new_system'";

    $source = strtolower(trim((string)($_GET['source'] ?? '')));
    if (!in_array($source, ['old_system', 'new_system'], true)) {
        $source = '';
    }

    $group = strtolower(trim((string)($_GET['group'] ?? DonorCampaignGroups::IMMEDIATE)));
    if (!DonorCampaignGroups::isValid($group)) {
        $group = DonorCampaignGroups::IMMEDIATE;
    }

    $search = trim((string)($_GET['donor'] ?? ''));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 25)));
    $offset = ($page - 1) * $perPage;

    $sortBy = strtolower(trim((string)($_GET['sort_by'] ?? 'name')));
    $sortOrder = strtolower(trim((string)($_GET['sort_order'] ?? 'asc')));
    $validSort = [
        'name' => 'd.name',
        'pledged' => 'd.total_pledged',
        'paid' => 'd.total_paid',
        'balance' => 'd.balance',
        'source' => $hasDataSource ? 'd.data_source' : 'd.name',
        'phone' => 'd.phone',
    ];
    if (!isset($validSort[$sortBy])) {
        $sortBy = 'name';
    }
    if (!in_array($sortOrder, ['asc', 'desc'], true)) {
        $sortOrder = 'asc';
    }

    $emptySummary = [
        'donors' => 0,
        'pledged' => 0.0,
        'paid' => 0.0,
        'remaining' => 0.0,
    ];
    $summary = [
        DonorCampaignGroups::IMMEDIATE => $emptySummary,
        DonorCampaignGroups::PLEDGE_COMPLETED => $emptySummary,
        DonorCampaignGroups::PLEDGE_PAYING => $emptySummary,
        DonorCampaignGroups::PLEDGE_NOT_STARTED => $emptySummary,
        DonorCampaignGroups::UNCLASSIFIED => $emptySummary,
        'total_donors' => 0,
        'pledge_donors' => 0,
    ];

    $summaryWhere = '1=1';
    $summaryParams = [];
    $summaryTypes = '';
    if ($hasDataSource && $source !== '') {
        $summaryWhere = 'd.data_source = ?';
        $summaryParams[] = $source;
        $summaryTypes = 's';
    }

    $summarySql = "
        SELECT
            {$groupExpr} AS campaign_group,
            COUNT(*) AS donors,
            COALESCE(SUM(d.total_pledged), 0) AS pledged,
            COALESCE(SUM(d.total_paid), 0) AS paid,
            COALESCE(SUM(d.balance), 0) AS remaining
        FROM donors d
        WHERE {$summaryWhere}
        GROUP BY campaign_group
    ";
    $summaryRes = dvc_query($db, $summarySql, $summaryTypes, $summaryParams);
    while ($row = $summaryRes->fetch_assoc()) {
        $key = (string)($row['campaign_group'] ?? '');
        if (!isset($summary[$key]) || !is_array($summary[$key])) {
            continue;
        }
        $count = (int)($row['donors'] ?? 0);
        $summary[$key] = [
            'donors' => $count,
            'pledged' => (float)($row['pledged'] ?? 0),
            'paid' => (float)($row['paid'] ?? 0),
            'remaining' => (float)($row['remaining'] ?? 0),
        ];
        $summary['total_donors'] += $count;
        if ($key !== DonorCampaignGroups::IMMEDIATE && $key !== DonorCampaignGroups::UNCLASSIFIED) {
            $summary['pledge_donors'] += $count;
        }
    }

    $where = ["({$groupExpr}) = ?"];
    $params = [$group];
    $types = 's';
    if ($hasDataSource && $source !== '') {
        $where[] = 'd.data_source = ?';
        $params[] = $source;
        $types .= 's';
    }
    if ($search !== '') {
        $where[] = '(d.name LIKE ? OR d.phone LIKE ? OR EXISTS (
            SELECT 1 FROM pledges p
            WHERE p.donor_id = d.id
              AND p.notes LIKE ?
        ))';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like);
        $types .= 'sss';
    }
    $whereClause = 'WHERE ' . implode(' AND ', $where);

    $countSql = "SELECT COUNT(*) AS total FROM donors d {$whereClause}";
    $totalRows = (int)(dvc_query($db, $countSql, $types, $params)->fetch_assoc()['total'] ?? 0);

    $orderColumn = $validSort[$sortBy];
    $dataSql = "
        SELECT
            d.id,
            d.name,
            d.phone,
            d.donor_type,
            d.total_pledged,
            d.total_paid,
            d.balance,
            {$sourceSelect} AS data_source,
            {$groupExpr} AS campaign_group,
            (
                SELECT p.notes
                FROM pledges p
                WHERE p.donor_id = d.id
                  AND p.status IN ('approved', 'pending')
                  AND p.notes REGEXP '^[0-9]{4}$'
                ORDER BY (p.status = 'approved') DESC, p.id DESC
                LIMIT 1
            ) AS reference
        FROM donors d
        {$whereClause}
        ORDER BY {$orderColumn} {$sortOrder}, d.id ASC
        LIMIT {$perPage} OFFSET {$offset}
    ";

    $rows = [];
    $dataRes = dvc_query($db, $dataSql, $types, $params);
    while ($row = $dataRes->fetch_assoc()) {
        $rows[] = [
            'id' => (int)($row['id'] ?? 0),
            'donor_id' => (int)($row['id'] ?? 0),
            'name' => (string)($row['name'] ?? ''),
            'phone' => (string)($row['phone'] ?? ''),
            'reference' => (string)($row['reference'] ?? ''),
            'donor_type' => (string)($row['donor_type'] ?? ''),
            'pledged' => (float)($row['total_pledged'] ?? 0),
            'paid' => (float)($row['total_paid'] ?? 0),
            'balance' => (float)($row['balance'] ?? 0),
            'data_source' => (string)($row['data_source'] ?? 'new_system'),
            'campaign_group' => (string)($row['campaign_group'] ?? $group),
        ];
    }

    $totalPages = $perPage > 0 ? (int)ceil($totalRows / $perPage) : 1;

    echo json_encode([
        'summary' => $summary,
        'group' => $group,
        'total_count' => $totalRows,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => $totalPages,
        'rows' => $rows,
        'has_data_source' => $hasDataSource,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to load campaign donors.',
    ]);
    error_log('Campaign donors API error: ' . $e->getMessage());
}
