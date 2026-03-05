<?php
declare(strict_types=1);

require_once '../../../config/db.php';
require_once '../../../shared/auth.php';

header('Content-Type: application/json');

require_login();
require_admin();

try {
    $db = db();

    $payment_columns = [];
    $col_query = $db->query("SHOW COLUMNS FROM payments");
    while ($col = $col_query->fetch_assoc()) {
        $payment_columns[] = $col['Field'];
    }
    $date_col = in_array('payment_date', $payment_columns) ? 'payment_date' : (in_array('received_at', $payment_columns) ? 'received_at' : 'created_at');
    $method_col = in_array('payment_method', $payment_columns) ? 'payment_method' : 'method';
    $ref_col = in_array('transaction_ref', $payment_columns) ? 'transaction_ref' : 'reference';
    $approver_col = in_array('approved_by_user_id', $payment_columns) ? 'approved_by_user_id' : (in_array('received_by_user_id', $payment_columns) ? 'received_by_user_id' : 'id');
    $has_donor_id = in_array('donor_id', $payment_columns);

    $donorSearch = trim($_GET['donor'] ?? '');
    $dateFrom = trim($_GET['date_from'] ?? '');
    $dateTo = trim($_GET['date_to'] ?? '');
    $amountMin = $_GET['amount_min'] !== '' && $_GET['amount_min'] !== null ? (float)$_GET['amount_min'] : null;
    $amountMax = $_GET['amount_max'] !== '' && $_GET['amount_max'] !== null ? (float)$_GET['amount_max'] : null;
    $paymentMethod = trim($_GET['payment_method'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 25)));
    $offset = ($page - 1) * $perPage;

    $sortBy = strtolower(trim($_GET['sort_by'] ?? $date_col));
    $sortOrder = strtolower(trim($_GET['sort_order'] ?? 'desc'));
    $validSortColumns = [
        'donor' => 'COALESCE(d.name, p.donor_name)',
        'amount' => 'p.amount',
        'date' => "p.{$date_col}",
        'method' => "p.{$method_col}",
    ];
    if (!isset($validSortColumns[$sortBy])) {
        $sortBy = 'date';
    }
    $orderColumn = $validSortColumns[$sortBy];
    if (!in_array($sortOrder, ['asc', 'desc'], true)) {
        $sortOrder = 'desc';
    }

    $where = ["p.status = 'approved'"];
    $params = [];
    $types = '';
    if ($donorSearch !== '') {
        $where[] = "(d.name LIKE ? OR d.phone LIKE ? OR p.donor_name LIKE ? OR p.donor_phone LIKE ?)";
        $sp = '%' . $donorSearch . '%';
        $params[] = $sp;
        $params[] = $sp;
        $params[] = $sp;
        $params[] = $sp;
        $types .= 'ssss';
    }
    if ($dateFrom !== '') {
        $where[] = "p.{$date_col} >= ?";
        $params[] = $dateFrom . ' 00:00:00';
        $types .= 's';
    }
    if ($dateTo !== '') {
        $where[] = "p.{$date_col} <= ?";
        $params[] = $dateTo . ' 23:59:59';
        $types .= 's';
    }
    if ($amountMin !== null && $amountMin > 0) {
        $where[] = "p.amount >= ?";
        $params[] = $amountMin;
        $types .= 'd';
    }
    if ($amountMax !== null && $amountMax > 0) {
        $where[] = "p.amount <= ?";
        $params[] = $amountMax;
        $types .= 'd';
    }
    if ($paymentMethod !== '') {
        $validMethods = ['bank_transfer', 'card', 'cash', 'cheque', 'other'];
        if (in_array($paymentMethod, $validMethods, true)) {
            $where[] = "p.{$method_col} = ?";
            $params[] = $paymentMethod;
            $types .= 's';
        }
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);
    $joinClause = $has_donor_id ? 'LEFT JOIN donors d ON p.donor_id = d.id' : 'LEFT JOIN donors d ON p.donor_phone = d.phone';

    $countSql = "SELECT COUNT(*) as total FROM payments p {$joinClause} {$whereClause}";
    if (!empty($params)) {
        $stmt = $db->prepare($countSql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $totalRows = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
    } else {
        $totalRows = (int)($db->query($countSql)->fetch_assoc()['total'] ?? 0);
    }

    $sumSql = "SELECT COALESCE(SUM(p.amount), 0) as total_amount FROM payments p {$joinClause} {$whereClause}";
    if (!empty($params)) {
        $stmt = $db->prepare($sumSql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $totalAmount = (float)($stmt->get_result()->fetch_assoc()['total_amount'] ?? 0);
        $stmt->close();
    } else {
        $totalAmount = (float)($db->query($sumSql)->fetch_assoc()['total_amount'] ?? 0);
    }

    $orderBy = "{$orderColumn} {$sortOrder}, p.id DESC";
    $dataSql = "
        SELECT
            p.id,
            p.donor_id,
            COALESCE(d.name, p.donor_name) as donor_name,
            COALESCE(d.phone, p.donor_phone) as donor_phone,
            p.amount,
            p.{$date_col} as payment_date,
            p.{$method_col} as payment_method,
            p.{$ref_col} as reference
        FROM payments p
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
            $method = (string)($r['payment_method'] ?? '');
            $methodDisplay = match ($method) {
                'bank_transfer' => 'Bank Transfer',
                'card' => 'Card',
                'cash' => 'Cash',
                'cheque' => 'Cheque',
                default => $method ?: 'Other',
            };
            $rows[] = [
                'id' => (int)($r['id'] ?? 0),
                'donor_id' => (int)($r['donor_id'] ?? 0),
                'donor_name' => (string)($r['donor_name'] ?? 'Unknown'),
                'donor_phone' => (string)($r['donor_phone'] ?? ''),
                'amount' => (float)($r['amount'] ?? 0),
                'payment_date' => (string)($r['payment_date'] ?? ''),
                'payment_method' => $methodDisplay,
                'reference' => (string)($r['reference'] ?? ''),
            ];
        }
        $stmt->close();
    } else {
        $res = $db->query($dataSql);
        while ($r = $res->fetch_assoc()) {
            $method = (string)($r['payment_method'] ?? '');
            $methodDisplay = match ($method) {
                'bank_transfer' => 'Bank Transfer',
                'card' => 'Card',
                'cash' => 'Cash',
                'cheque' => 'Cheque',
                default => $method ?: 'Other',
            };
            $rows[] = [
                'id' => (int)($r['id'] ?? 0),
                'donor_id' => (int)($r['donor_id'] ?? 0),
                'donor_name' => (string)($r['donor_name'] ?? 'Unknown'),
                'donor_phone' => (string)($r['donor_phone'] ?? ''),
                'amount' => (float)($r['amount'] ?? 0),
                'payment_date' => (string)($r['payment_date'] ?? ''),
                'payment_method' => $methodDisplay,
                'reference' => (string)($r['reference'] ?? ''),
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
            'donor' => $donorSearch,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'amount_min' => $amountMin,
            'amount_max' => $amountMax,
            'payment_method' => $paymentMethod,
        ],
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Database error',
        'message' => $e->getMessage(),
    ]);
    error_log('Direct Payments API Error: ' . $e->getMessage());
}
