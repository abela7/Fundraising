<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

$db = db();
$plan_id = 12;

echo "<h2>Debugging Payment Plan Query</h2>";
echo "<pre>";

// Test 1: Check if plan exists
echo "=== TEST 1: Check if plan exists ===\n";
$test1 = $db->query("SELECT id FROM donor_payment_plans WHERE id = $plan_id");
if ($test1 && $test1->num_rows > 0) {
    echo "✅ Plan #$plan_id exists\n\n";
} else {
    echo "❌ Plan #$plan_id NOT found\n\n";
    exit;
}

// Test 2: Check donor_payment_plans columns
echo "=== TEST 2: donor_payment_plans columns ===\n";
$cols = $db->query("SHOW COLUMNS FROM donor_payment_plans");
while ($col = $cols->fetch_assoc()) {
    echo "- " . $col['Field'] . " (" . $col['Type'] . ")\n";
}
echo "\n";

// Test 3: Check donors columns
echo "=== TEST 3: donors columns ===\n";
$cols = $db->query("SHOW COLUMNS FROM donors");
while ($col = $cols->fetch_assoc()) {
    echo "- " . $col['Field'] . " (" . $col['Type'] . ")\n";
}
echo "\n";

// Test 4: Check pledges columns
echo "=== TEST 4: pledges columns ===\n";
$cols = $db->query("SHOW COLUMNS FROM pledges");
while ($col = $cols->fetch_assoc()) {
    echo "- " . $col['Field'] . " (" . $col['Type'] . ")\n";
}
echo "\n";

// Test 5: Try the full query
echo "=== TEST 5: Full query test ===\n";
$query = "
    SELECT 
        pp.*,
        d.id as donor_id,
        d.name as donor_name,
        d.phone as donor_phone,
        d.email as donor_email,
        d.balance as donor_balance,
        d.total_paid as donor_total_paid,
        p.id as pledge_id,
        p.amount as pledge_amount,
        p.notes as pledge_notes,
        p.created_at as pledge_date,
        t.name as template_name,
        t.description as template_description,
        c.name as church_name,
        c.city as church_city,
        r.name as representative_name,
        r.phone as representative_phone
    FROM donor_payment_plans pp
    INNER JOIN donors d ON pp.donor_id = d.id
    LEFT JOIN pledges p ON pp.pledge_id = p.id
    LEFT JOIN payment_plan_templates t ON pp.template_id = t.id
    LEFT JOIN churches c ON d.church_id = c.id
    LEFT JOIN church_representatives r ON d.representative_id = r.id
    WHERE pp.id = $plan_id
";

$result = $db->query($query);
if ($result) {
    echo "✅ Query executed successfully\n";
    if ($result->num_rows > 0) {
        echo "✅ Found " . $result->num_rows . " row(s)\n";
        $row = $result->fetch_assoc();
        echo "\n--- Sample Data ---\n";
        echo "Donor: " . $row['donor_name'] . "\n";
        echo "Monthly Amount: £" . number_format($row['monthly_amount'], 2) . "\n";
        echo "Pledge Amount: £" . ($row['pledge_amount'] ? number_format($row['pledge_amount'], 2) : 'NULL') . "\n";
    } else {
        echo "⚠️ Query returned 0 rows\n";
    }
} else {
    echo "❌ Query failed: " . $db->error . "\n";
}

echo "</pre>";
?>

