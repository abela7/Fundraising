<?php
declare(strict_types=1);

require_once '../../../config/db.php';
require_once '../../../shared/auth.php';

header('Content-Type: application/json');

require_login();
require_admin();

try {
    $db = db();

    // Check if pledge_payments table exists
    $ppCheck = $db->query("SHOW TABLES LIKE 'pledge_payments'");
    if (!$ppCheck || $ppCheck->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Pledge payments table not found', 'enabled' => false]);
        exit;
    }

    // Parse float filter safely
    $parseFloat = static function (string $key): ?float {
        if (!isset($_GET[$key]) || $_GET[$key] === null) return null;
        $v = is_scalar($_GET[$key]) ? trim((string)$_GET[$key]) : '';
        if ($v === '' || !is_numeric($v)) return null;
        $n = (float)$v;
        return $n >= 0 ? $n : null;
    };

    // Filters
    $dateFrom = trim($_GET['date_from'] ?? '');
    $dateTo = trim($_GET['date_to'] ?? '');
    $donorSearch = trim($_GET['donor'] ?? '');
    $paymentMethod = trim($_GET['payment_method'] ?? '');
    $amountMin = $parseFloat('amount_min');
    $amountMax = $parseFloat('amount_max');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 25)));
    $offset = ($page - 1) * $perPage;

    $where = ["pp.status = 'confirmed'"];
    $params = [];
    $types = '';

    if ($dateFrom !== '') {
        $where[] = "pp.payment_date >= ?";
        $params[] = $dateFrom . ' 00:00:00';
        $types .= 's';
    }
    if ($dateTo !== '') {
        $where[] = "pp.payment_date <= ?";
        $params[] = $dateTo . ' 23:59:59';
        $types .= 's';
    }
    if ($donorSearch !== '') {
        $where[] = "(d.name LIKE ? OR d.phone LIKE ? OR pp.reference_number LIKE ?)";
        $searchParam = '%' . $donorSearch . '%';
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $types .= 'sss';
    }
    if ($paymentMethod !== '') {
        $validMethods = ['bank_transfer', 'card', 'cash', 'cheque', 'other'];
        if (in_array($paymentMethod, $validMethods, true)) {
            $where[] = "pp.payment_method = ?";
            $params[] = $paymentMethod;
            $types .= 's';
        }
    }
    if ($amountMin !== null) {
        $where[] = "pp.amount >= ?";
        $params[] = $amountMin;
        $types .= 'd';
    }
    if ($amountMax !== null) {
        $where[] = "pp.amount <= ?";
        $params[] = $amountMax;
        $types .= 'd';
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);

    // Total count
    $countSql = "
        SELECT COUNT(*) as total
        FROM pledge_payments pp
        LEFT JOIN donors d ON pp.donor_id = d.id
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
        SELECT COALESCE(SUM(pp.amount), 0) as total_amount
        FROM pledge_payments pp
        LEFT JOIN donors d ON pp.donor_id = d.id
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
    $orderBy = 'pp.payment_date DESC, pp.id DESC';
    $dataSql = "
        SELECT
            pp.id,
            pp.pledge_id,
            pp.donor_id,
            pp.amount,
            pp.payment_method,
            pp.payment_date,
            pp.reference_number,
            pp.notes,
            pp.created_at,
            pp.approved_by_user_id,
            d.name as donor_name,
            d.phone as donor_phone,
            pl.amount as pledge_amount,
            approver.name as approved_by_name
        FROM pledge_payments pp
        LEFT JOIN donors d ON pp.donor_id = d.id
        LEFT JOIN pledges pl ON pp.pledge_id = pl.id
        LEFT JOIN users approver ON pp.approved_by_user_id = approver.id
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
                default => 'Other',
            };
            $rows[] = [
                'id' => (int)($r['id'] ?? 0),
                'pledge_id' => (int)($r['pledge_id'] ?? 0),
                'donor_id' => (int)($r['donor_id'] ?? 0),
                'donor_name' => (string)($r['donor_name'] ?? 'Unknown'),
                'donor_phone' => (string)($r['donor_phone'] ?? ''),
                'amount' => (float)($r['amount'] ?? 0),
                'payment_method' => $methodDisplay,
                'payment_method_raw' => $method,
                'payment_date' => (string)($r['payment_date'] ?? ''),
                'reference_number' => (string)($r['reference_number'] ?? ''),
                'notes' => (string)($r['notes'] ?? ''),
                'created_at' => (string)($r['created_at'] ?? ''),
                'approved_by' => (string)($r['approved_by_name'] ?? ''),
                'pledge_amount' => (float)($r['pledge_amount'] ?? 0),
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
                default => 'Other',
            };
            $rows[] = [
                'id' => (int)($r['id'] ?? 0),
                'pledge_id' => (int)($r['pledge_id'] ?? 0),
                'donor_id' => (int)($r['donor_id'] ?? 0),
                'donor_name' => (string)($r['donor_name'] ?? 'Unknown'),
                'donor_phone' => (string)($r['donor_phone'] ?? ''),
                'amount' => (float)($r['amount'] ?? 0),
                'payment_method' => $methodDisplay,
                'payment_method_raw' => $method,
                'payment_date' => (string)($r['payment_date'] ?? ''),
                'reference_number' => (string)($r['reference_number'] ?? ''),
                'notes' => (string)($r['notes'] ?? ''),
                'created_at' => (string)($r['created_at'] ?? ''),
                'approved_by' => (string)($r['approved_by_name'] ?? ''),
                'pledge_amount' => (float)($r['pledge_amount'] ?? 0),
            ];
        }
    }

    $totalPages = $perPage > 0 ? (int)ceil($totalRows / $perPage) : 1;

    echo json_encode([
        'enabled' => true,
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
            'payment_method' => $paymentMethod,
            'amount_min' => $amountMin,
            'amount_max' => $amountMax,
        ],
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Database error',
        'message' => $e->getMessage(),
    ]);
    error_log('Paid Towards Pledges API Error: ' . $e->getMessage());
}
