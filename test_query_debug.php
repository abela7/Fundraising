<?php
// test_query_debug.php
// Place this in the project root: c:\xampp\htdocs\Fundraising\test_query_debug.php

require_once __DIR__ . '/config/db.php';
$db = db();

echo "<pre>\n";
echo "=== DEBUGGING QUERY PERFORMANCE ===\n\n";

// Filters from user query
$filter_amount_min = 29.0;
$filter_amount_max = 31.0;
$filter_donor = '';
$filter_registrar = '';
$filter_date_from = '';
$filter_date_to = '';

// Build conditions
$where_conditions = ["p.status = 'approved'"];
$payment_where_conditions = ["pay.status = 'approved'"];
$batch_conditions = [];

// Amount filter
$where_conditions[] = 'p.amount >= ' . $filter_amount_min;
$payment_where_conditions[] = 'pay.amount >= ' . $filter_amount_min;
$batch_conditions[] = "b.additional_amount >= " . $filter_amount_min;

$where_conditions[] = 'p.amount <= ' . $filter_amount_max;
$payment_where_conditions[] = 'pay.amount <= ' . $filter_amount_max;
$batch_conditions[] = "b.additional_amount <= " . $filter_amount_max;

$where_clause = implode(' AND ', $where_conditions);
$payment_where_clause = implode(' AND ', $payment_where_conditions);
$batch_where = !empty($batch_conditions) ? "AND " . implode(' AND ', $batch_conditions) : "";

echo "WHERE Clause (Pledges): $where_clause\n";
echo "WHERE Clause (Payments): $payment_where_clause\n";
echo "WHERE Clause (Batches): $batch_where\n\n";

// 1. Test Pledges Query
echo "--- Testing Pledges Query ---\n";
$sql_pledges = "SELECT p.id, p.amount FROM pledges p LEFT JOIN users u ON p.created_by_user_id = u.id WHERE $where_clause";
$t0 = microtime(true);
$res = $db->query($sql_pledges);
$t1 = microtime(true);
if (!$res) die("Pledges Query Failed: " . $db->error);
echo "Rows: " . $res->num_rows . "\n";
echo "Time: " . round(($t1 - $t0) * 1000, 2) . " ms\n\n";

// 2. Test Payments Query
echo "--- Testing Payments Query ---\n";
$sql_payments = "SELECT pay.id, pay.amount FROM payments pay LEFT JOIN users u ON pay.received_by_user_id = u.id WHERE $payment_where_clause";
$t0 = microtime(true);
$res = $db->query($sql_payments);
$t1 = microtime(true);
if (!$res) die("Payments Query Failed: " . $db->error);
echo "Rows: " . $res->num_rows . "\n";
echo "Time: " . round(($t1 - $t0) * 1000, 2) . " ms\n\n";

// 3. Test Batches Query
echo "--- Testing Batches Query ---\n";
$sql_batches = "SELECT b.id, b.additional_amount 
    FROM grid_allocation_batches b
    LEFT JOIN pledges p ON b.original_pledge_id = p.id
    WHERE b.approval_status = 'approved'
      AND b.batch_type IN ('pledge_update', 'payment_update')
      AND b.original_pledge_id IS NOT NULL
      $batch_where";
$t0 = microtime(true);
$res = $db->query($sql_batches);
$t1 = microtime(true);
if (!$res) die("Batches Query Failed: " . $db->error);
echo "Rows: " . $res->num_rows . "\n";
echo "Time: " . round(($t1 - $t0) * 1000, 2) . " ms\n\n";

// 4. Test UNION COUNT Query
echo "--- Testing FULL COUNT Query ---\n";
$count_sql = "
SELECT COUNT(*) as total FROM (
  (SELECT p.id FROM pledges p 
   LEFT JOIN users u ON p.created_by_user_id = u.id 
   WHERE $where_clause)
  UNION ALL
  (SELECT pay.id FROM payments pay 
   LEFT JOIN users u ON pay.received_by_user_id = u.id 
   WHERE $payment_where_clause)
  UNION ALL
  ($sql_batches)
) as combined_count";

$t0 = microtime(true);
$res = $db->query($count_sql);
$t1 = microtime(true);
if (!$res) die("Count Query Failed: " . $db->error);
$row = $res->fetch_assoc();
echo "Total Count: " . $row['total'] . "\n";
echo "Time: " . round(($t1 - $t0) * 1000, 2) . " ms\n";

echo "</pre>";

