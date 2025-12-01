<?php
/**
 * Data Diagnostic - Check donor financial data
 * Temporary file for debugging
 */
require_once __DIR__ . '/../includes/auth.php';

$donor_id = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0;

if (!$donor_id) {
    die("Please provide donor_id in URL: ?donor_id=123");
}

echo "<h1>Data Diagnostic for Donor #$donor_id</h1>";
echo "<style>table{border-collapse:collapse;margin:20px 0;}th,td{border:1px solid #ccc;padding:8px;text-align:left;}th{background:#f5f5f5;}.section{margin:30px 0;padding:20px;background:#f9f9f9;border-radius:8px;}</style>";

// 1. Donor table data
echo "<div class='section'>";
echo "<h2>1. DONORS TABLE</h2>";
$result = $db->query("SELECT id, name, phone, total_pledged, total_paid, balance, payment_status, donor_type FROM donors WHERE id = $donor_id");
if ($result && $row = $result->fetch_assoc()) {
    echo "<table>";
    foreach ($row as $key => $value) {
        echo "<tr><th>$key</th><td>" . htmlspecialchars($value ?? 'NULL') . "</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color:red;'>Donor not found!</p>";
}
echo "</div>";

// 2. Pledges table
echo "<div class='section'>";
echo "<h2>2. PLEDGES TABLE</h2>";
$result = $db->query("SHOW TABLES LIKE 'pledges'");
if ($result && $result->num_rows > 0) {
    echo "<p style='color:green;'>✓ Table EXISTS</p>";
    
    // Show columns
    echo "<h4>Columns:</h4>";
    $cols = $db->query("SHOW COLUMNS FROM pledges");
    $col_names = [];
    while ($col = $cols->fetch_assoc()) {
        $col_names[] = $col['Field'];
    }
    echo "<p>" . implode(", ", $col_names) . "</p>";
    
    // Show data
    echo "<h4>Data for donor $donor_id:</h4>";
    $pledges = $db->query("SELECT * FROM pledges WHERE donor_id = $donor_id");
    if ($pledges && $pledges->num_rows > 0) {
        echo "<table><tr>";
        foreach ($col_names as $col) echo "<th>$col</th>";
        echo "</tr>";
        while ($row = $pledges->fetch_assoc()) {
            echo "<tr>";
            foreach ($col_names as $col) echo "<td>" . htmlspecialchars($row[$col] ?? 'NULL') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // Sum
        $sum = $db->query("SELECT SUM(amount) as total, COUNT(*) as count FROM pledges WHERE donor_id = $donor_id");
        $s = $sum->fetch_assoc();
        echo "<p><strong>Total pledges: {$s['count']}, Sum: £{$s['total']}</strong></p>";
        
        // Check status values
        $statuses = $db->query("SELECT DISTINCT status FROM pledges WHERE donor_id = $donor_id");
        echo "<p>Status values found: ";
        while ($st = $statuses->fetch_assoc()) echo "'{$st['status']}' ";
        echo "</p>";
    } else {
        echo "<p style='color:orange;'>No pledges found for this donor</p>";
    }
} else {
    echo "<p style='color:red;'>✗ Table DOES NOT EXIST</p>";
}
echo "</div>";

// 3. Payments table
echo "<div class='section'>";
echo "<h2>3. PAYMENTS TABLE</h2>";
$result = $db->query("SHOW TABLES LIKE 'payments'");
if ($result && $result->num_rows > 0) {
    echo "<p style='color:green;'>✓ Table EXISTS</p>";
    
    // Show columns
    echo "<h4>Columns:</h4>";
    $cols = $db->query("SHOW COLUMNS FROM payments");
    $col_names = [];
    while ($col = $cols->fetch_assoc()) {
        $col_names[] = $col['Field'];
    }
    echo "<p>" . implode(", ", $col_names) . "</p>";
    
    // Show data
    echo "<h4>Data for donor $donor_id:</h4>";
    $payments = $db->query("SELECT * FROM payments WHERE donor_id = $donor_id LIMIT 20");
    if ($payments && $payments->num_rows > 0) {
        echo "<table><tr>";
        foreach ($col_names as $col) echo "<th>$col</th>";
        echo "</tr>";
        while ($row = $payments->fetch_assoc()) {
            echo "<tr>";
            foreach ($col_names as $col) echo "<td>" . htmlspecialchars($row[$col] ?? 'NULL') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // Sum
        $sum = $db->query("SELECT SUM(amount) as total, COUNT(*) as count FROM payments WHERE donor_id = $donor_id");
        $s = $sum->fetch_assoc();
        echo "<p><strong>Total payments: {$s['count']}, Sum: £{$s['total']}</strong></p>";
        
        // Check status values
        $statuses = $db->query("SELECT DISTINCT status FROM payments WHERE donor_id = $donor_id");
        echo "<p>Status values found: ";
        while ($st = $statuses->fetch_assoc()) echo "'{$st['status']}' ";
        echo "</p>";
    } else {
        echo "<p style='color:orange;'>No payments found for this donor</p>";
    }
} else {
    echo "<p style='color:red;'>✗ Table DOES NOT EXIST</p>";
}
echo "</div>";

// 4. Payment Plans
echo "<div class='section'>";
echo "<h2>4. PAYMENT PLANS</h2>";

// Check different possible table names
$plan_tables = ['donor_payment_plans', 'payment_plans', 'pledges'];
foreach ($plan_tables as $table) {
    $result = $db->query("SHOW TABLES LIKE '$table'");
    if ($result && $result->num_rows > 0) {
        echo "<p style='color:green;'>✓ Table '$table' EXISTS</p>";
    }
}

$result = $db->query("SHOW TABLES LIKE '%plan%'");
if ($result) {
    echo "<h4>All tables containing 'plan':</h4><ul>";
    while ($row = $result->fetch_array()) {
        echo "<li>{$row[0]}</li>";
    }
    echo "</ul>";
}
echo "</div>";

// 5. What conversation.php would calculate
echo "<div class='section'>";
echo "<h2>5. CALCULATED VALUES (what modal should show)</h2>";

$calc_pledged = $db->query("SELECT COALESCE(SUM(amount), 0) as total FROM pledges WHERE donor_id = $donor_id AND status = 'approved'");
$pledged = $calc_pledged ? $calc_pledged->fetch_assoc()['total'] : 0;

$calc_paid = $db->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE donor_id = $donor_id AND status = 'completed'");
$paid = $calc_paid ? $calc_paid->fetch_assoc()['total'] : 0;

$balance = $pledged - $paid;

echo "<table>";
echo "<tr><th>calc_total_pledged (pledges where status='approved')</th><td>£" . number_format($pledged, 2) . "</td></tr>";
echo "<tr><th>calc_total_paid (payments where status='completed')</th><td>£" . number_format($paid, 2) . "</td></tr>";
echo "<tr><th>balance (pledged - paid)</th><td>£" . number_format($balance, 2) . "</td></tr>";
echo "</table>";

// Try different status values
echo "<h4>Try different status values:</h4>";
$statuses_to_try = ['approved', 'active', 'pending', 'confirmed', 'completed'];
foreach ($statuses_to_try as $status) {
    $r = $db->query("SELECT COALESCE(SUM(amount), 0) as total FROM pledges WHERE donor_id = $donor_id AND status = '$status'");
    $total = $r ? $r->fetch_assoc()['total'] : 0;
    if ($total > 0) {
        echo "<p>Pledges with status='$status': £" . number_format($total, 2) . "</p>";
    }
}

// Total without status filter
$r = $db->query("SELECT COALESCE(SUM(amount), 0) as total FROM pledges WHERE donor_id = $donor_id");
$total = $r ? $r->fetch_assoc()['total'] : 0;
echo "<p><strong>Pledges without status filter: £" . number_format($total, 2) . "</strong></p>";

echo "</div>";
?>

