<?php
declare(strict_types=1);

require_once '../../../config/db.php';
require_once '../../../shared/auth.php';

header('Content-Type: application/json');

require_login();
require_admin();

$donorId = (int)($_GET['donor_id'] ?? 0);
if ($donorId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid donor_id']);
    exit;
}

try {
    $db = db();

    // Get donor
    $donorStmt = $db->prepare("SELECT id, name, phone, total_pledged, total_paid, balance FROM donors WHERE id = ?");
    $donorStmt->bind_param('i', $donorId);
    $donorStmt->execute();
    $donor = $donorStmt->get_result()->fetch_assoc();
    $donorStmt->close();

    if (!$donor) {
        http_response_code(404);
        echo json_encode(['error' => 'Donor not found']);
        exit;
    }

    // Payment columns
    $payment_columns = [];
    $col_query = $db->query("SHOW COLUMNS FROM payments");
    while ($col = $col_query->fetch_assoc()) {
        $payment_columns[] = $col['Field'];
    }
    $pay_date_col = in_array('payment_date', $payment_columns) ? 'payment_date' : (in_array('received_at', $payment_columns) ? 'received_at' : 'created_at');

    $has_pledge_payments = $db->query("SHOW TABLES LIKE 'pledge_payments'")->num_rows > 0;
    $pp_date_col = 'payment_date';
    if ($has_pledge_payments) {
        $pp_col_check = $db->query("SHOW COLUMNS FROM pledge_payments LIKE 'payment_date'");
        if (!$pp_col_check || $pp_col_check->num_rows === 0) {
            $pp_date_col = 'created_at';
        }
    }

    // Approved pledges
    $calc_pledges = [];
    $pledge_breakdown_stmt = $db->prepare("SELECT id, amount, status, created_at FROM pledges WHERE donor_id = ? AND status = 'approved' ORDER BY created_at ASC");
    $pledge_breakdown_stmt->bind_param('i', $donorId);
    $pledge_breakdown_stmt->execute();
    $pr = $pledge_breakdown_stmt->get_result();
    while ($row = $pr->fetch_assoc()) {
        $calc_pledges[] = [
            'id' => (int)$row['id'],
            'amount' => (float)$row['amount'],
            'status' => $row['status'],
            'created_at' => $row['created_at'],
        ];
    }
    $pledge_breakdown_stmt->close();

    // Approved direct payments
    $calc_payments_direct = [];
    $pay_phone = $donor['phone'] ?? '';
    $pay_direct_stmt = $db->prepare("SELECT id, amount, status, {$pay_date_col} as payment_date FROM payments WHERE (donor_phone = ? OR donor_id = ?) AND status = 'approved' ORDER BY {$pay_date_col} ASC");
    $pay_direct_stmt->bind_param('si', $pay_phone, $donorId);
    $pay_direct_stmt->execute();
    $pdr = $pay_direct_stmt->get_result();
    while ($row = $pdr->fetch_assoc()) {
        $calc_payments_direct[] = [
            'id' => (int)$row['id'],
            'amount' => (float)$row['amount'],
            'status' => $row['status'],
            'payment_date' => $row['payment_date'],
        ];
    }
    $pay_direct_stmt->close();

    // Confirmed pledge payments
    $calc_pledge_payments = [];
    if ($has_pledge_payments) {
        $pp_stmt = $db->prepare("SELECT id, amount, status, {$pp_date_col} as payment_date FROM pledge_payments WHERE donor_id = ? AND status = 'confirmed' ORDER BY {$pp_date_col} ASC");
        $pp_stmt->bind_param('i', $donorId);
        $pp_stmt->execute();
        $ppr = $pp_stmt->get_result();
        while ($row = $ppr->fetch_assoc()) {
            $calc_pledge_payments[] = [
                'id' => (int)$row['id'],
                'amount' => (float)$row['amount'],
                'status' => $row['status'],
                'payment_date' => $row['payment_date'],
            ];
        }
        $pp_stmt->close();
    }

    $calc_pledged_total = array_sum(array_column($calc_pledges, 'amount'));
    $calc_direct_total = array_sum(array_column($calc_payments_direct, 'amount'));
    $calc_pp_total = array_sum(array_column($calc_pledge_payments, 'amount'));
    $calc_paid_total = $calc_direct_total + $calc_pp_total;
    $calc_balance = max(0, $calc_pledged_total - $calc_paid_total);

    $stored_balance = (float)($donor['balance'] ?? 0);
    $balance_mismatch = abs($stored_balance - $calc_balance) > 0.01;

    echo json_encode([
        'donor' => [
            'id' => (int)$donor['id'],
            'name' => $donor['name'] ?? 'Unknown',
            'phone' => $donor['phone'] ?? '',
            'total_pledged' => (float)($donor['total_pledged'] ?? 0),
            'total_paid' => (float)($donor['total_paid'] ?? 0),
            'balance' => $stored_balance,
        ],
        'breakdown' => [
            'pledges' => $calc_pledges,
            'pledged_total' => $calc_pledged_total,
            'payments_direct' => $calc_payments_direct,
            'direct_total' => $calc_direct_total,
            'pledge_payments' => $calc_pledge_payments,
            'pledge_payments_total' => $calc_pp_total,
            'paid_total' => $calc_paid_total,
            'calculated_balance' => $calc_balance,
            'balance_mismatch' => $balance_mismatch,
        ],
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Database error',
        'message' => $e->getMessage(),
    ]);
    error_log('Donor Breakdown API Error: ' . $e->getMessage());
}
