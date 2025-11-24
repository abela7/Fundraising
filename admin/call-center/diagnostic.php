<?php
declare(strict_types=1);
/**
 * EMERGENCY ERROR DIAGNOSTIC TOOL
 * 
 * Run this when a page is "frozen" or not working.
 * It checks the database and shows EXACTLY what's wrong.
 */

// Don't crash on errors - show them instead
ini_set('display_errors', '1');
error_reporting(E_ALL);

echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Database Diagnostic</title>";
echo "<style>
    body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
    .box { background: white; padding: 20px; margin: 10px 0; border-radius: 8px; border-left: 4px solid #333; }
    .success { border-left-color: #28a745; }
    .error { border-left-color: #dc3545; background: #fff5f5; }
    .warning { border-left-color: #ffc107; background: #fffbf0; }
    code { background: #f0f0f0; padding: 2px 6px; border-radius: 3px; }
    table { width: 100%; border-collapse: collapse; margin: 10px 0; }
    th, td { padding: 8px; text-align: left; border-bottom: 1px solid #ddd; }
    th { background: #f8f9fa; font-weight: bold; }
</style></head><body>";

echo "<h1>🔍 Database Diagnostic Tool</h1>";
echo "<p>Checking your database setup...</p>";

// Test 1: Can we load the config?
echo "<div class='box'>";
echo "<h3>Step 1: Loading Configuration</h3>";
try {
    require_once __DIR__ . '/../../config/env.php';
    echo "<p style='color: green;'>✓ Config loaded successfully</p>";
    echo "<p>Database: <code>" . DB_NAME . "</code></p>";
    echo "<p>Host: <code>" . DB_HOST . "</code></p>";
    echo "<p>Environment: <code>" . ENVIRONMENT . "</code></p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Config failed: " . $e->getMessage() . "</p>";
    die("</div></body></html>");
}
echo "</div>";

// Test 2: Can we connect to database?
echo "<div class='box'>";
echo "<h3>Step 2: Database Connection</h3>";
try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $db->set_charset('utf8mb4');
    echo "<p style='color: green;'>✓ Connected successfully</p>";
} catch (Exception $e) {
    echo "<div class='error'>";
    echo "<p style='color: red;'><strong>✗ Connection FAILED</strong></p>";
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Common fixes:</strong></p>";
    echo "<ul>";
    echo "<li>Start XAMPP MySQL service</li>";
    echo "<li>Check database name exists in phpMyAdmin</li>";
    echo "<li>Verify credentials in config/env.php</li>";
    echo "</ul>";
    echo "</div>";
    die("</div></body></html>");
}
echo "</div>";

// Test 3: Check donors table exists
echo "<div class='box'>";
echo "<h3>Step 3: Checking 'donors' Table</h3>";
$table_check = $db->query("SHOW TABLES LIKE 'donors'");
if ($table_check && $table_check->num_rows > 0) {
    echo "<p style='color: green;'>✓ Table 'donors' exists</p>";
} else {
    echo "<div class='error'>";
    echo "<p style='color: red;'><strong>✗ Table 'donors' NOT FOUND</strong></p>";
    echo "<p>You need to import the database SQL file in phpMyAdmin.</p>";
    echo "</div>";
    die("</div></body></html>");
}
echo "</div>";

// Test 4: Check required columns
echo "<div class='box'>";
echo "<h3>Step 4: Checking Required Columns</h3>";

$required_columns = [
    'id' => 'Primary key',
    'name' => 'Donor name',
    'phone' => 'Phone number',
    'email' => 'Email address',
    'balance' => 'Outstanding balance',
    'total_pledged' => 'Total pledged amount',
    'payment_status' => 'Payment status',
    'donor_type' => 'Donor type (pledge/immediate)',
    'agent_id' => 'Assigned agent ID',
    'church_id' => 'Church assignment'
];

$columns_result = $db->query("SHOW COLUMNS FROM donors");
$existing_columns = [];
while ($col = $columns_result->fetch_assoc()) {
    $existing_columns[] = $col['Field'];
}

$missing = [];
echo "<table>";
echo "<tr><th>Column</th><th>Purpose</th><th>Status</th></tr>";
foreach ($required_columns as $col_name => $description) {
    $exists = in_array($col_name, $existing_columns);
    $status = $exists ? "<span style='color: green;'>✓ EXISTS</span>" : "<span style='color: red;'>✗ MISSING</span>";
    echo "<tr><td><code>$col_name</code></td><td>$description</td><td>$status</td></tr>";
    if (!$exists) {
        $missing[] = $col_name;
    }
}
echo "</table>";

if (empty($missing)) {
    echo "<p style='color: green; font-weight: bold;'>✓ All required columns exist!</p>";
} else {
    echo "<div class='error' style='margin-top: 15px;'>";
    echo "<h4 style='color: red;'>⚠ Missing Columns Found</h4>";
    echo "<p>These columns are missing: <code>" . implode(', ', $missing) . "</code></p>";
    echo "<p><strong>To fix this:</strong></p>";
    echo "<ol>";
    echo "<li>Copy the SQL below</li>";
    echo "<li>Open <strong>phpMyAdmin</strong></li>";
    echo "<li>Select your database: <code>" . DB_NAME . "</code></li>";
    echo "<li>Click <strong>SQL</strong> tab</li>";
    echo "<li>Paste and run the SQL</li>";
    echo "</ol>";
    
    echo "<h5>Fix SQL:</h5>";
    echo "<textarea style='width: 100%; height: 200px; font-family: monospace; padding: 10px;'>";
    
    foreach ($missing as $col) {
        if ($col === 'donor_type') {
            echo "ALTER TABLE donors ADD COLUMN donor_type ENUM('immediate_payment', 'pledge') NOT NULL DEFAULT 'immediate_payment' AFTER id;\n";
            echo "UPDATE donors SET donor_type = CASE WHEN total_pledged > 0 THEN 'pledge' ELSE 'immediate_payment' END;\n";
            echo "ALTER TABLE donors ADD INDEX idx_donor_type (donor_type);\n\n";
        } elseif ($col === 'agent_id') {
            echo "ALTER TABLE donors ADD COLUMN agent_id INT NULL COMMENT 'Agent responsible for donor';\n";
            echo "ALTER TABLE donors ADD INDEX idx_agent (agent_id);\n\n";
        } elseif ($col === 'church_id') {
            echo "ALTER TABLE donors ADD COLUMN church_id INT NULL COMMENT 'Church assignment';\n";
            echo "ALTER TABLE donors ADD INDEX idx_church (church_id);\n\n";
        }
    }
    
    echo "</textarea>";
    echo "</div>";
}
echo "</div>";

// Test 5: Balance column check (common issue!)
echo "<div class='box'>";
echo "<h3>Step 5: Balance Column Check</h3>";
echo "<p>Checking if 'balance' is a GENERATED column (this can cause crashes)...</p>";

$balance_check = $db->query("SHOW COLUMNS FROM donors WHERE Field = 'balance'");
if ($balance_check && $balance_check->num_rows > 0) {
    $balance_col = $balance_check->fetch_assoc();
    $extra = $balance_col['Extra'] ?? '';
    
    if (strpos($extra, 'GENERATED') !== false || strpos($extra, 'VIRTUAL') !== false) {
        echo "<div class='warning'>";
        echo "<p style='color: orange;'><strong>⚠ WARNING: 'balance' is a GENERATED column</strong></p>";
        echo "<p>This can cause PHP crashes when used in complex queries with JOINs or WHERE clauses.</p>";
        echo "<p><strong>Solution:</strong> Calculate balance manually in SELECT queries instead:</p>";
        echo "<pre style='background: #f5f5f5; padding: 10px; border-radius: 4px;'>";
        echo "(COALESCE(total_pledged, 0) - COALESCE(total_paid, 0)) as balance";
        echo "</pre>";
        echo "<p>This is already implemented in the fixed assign-donors.php</p>";
        echo "</div>";
    } else {
        echo "<p style='color: green;'>✓ Balance is a regular column (safe to use)</p>";
    }
} else {
    echo "<div class='error'>";
    echo "<p style='color: red;'>✗ Balance column does not exist!</p>";
    echo "<p>You need to add it or calculate it in queries.</p>";
    echo "</div>";
}
echo "</div>";

// Test 6: Quick query test
echo "<div class='box'>";
echo "<h3>Step 6: Test Safe Query</h3>";
try {
    // Use calculated balance instead of column to avoid crashes
    $test = $db->query("SELECT COUNT(*) as total, 
        SUM(CASE WHEN donor_type = 'pledge' THEN 1 ELSE 0 END) as pledges,
        SUM(CASE WHEN agent_id IS NOT NULL THEN 1 ELSE 0 END) as assigned,
        SUM(COALESCE(total_pledged, 0) - COALESCE(total_paid, 0)) as total_outstanding
        FROM donors");
    
    if ($test) {
        $result = $test->fetch_assoc();
        echo "<p style='color: green;'>✓ Query successful!</p>";
        echo "<table>";
        echo "<tr><th>Metric</th><th>Count</th></tr>";
        echo "<tr><td>Total Donors</td><td>" . $result['total'] . "</td></tr>";
        echo "<tr><td>Pledge Donors</td><td>" . $result['pledges'] . "</td></tr>";
        echo "<tr><td>Assigned to Agents</td><td>" . $result['assigned'] . "</td></tr>";
        echo "<tr><td>Total Outstanding</td><td>£" . number_format((float)$result['total_outstanding'], 2) . "</td></tr>";
        echo "</table>";
    }
} catch (mysqli_sql_exception $e) {
    echo "<div class='error'>";
    echo "<p style='color: red;'><strong>✗ Query FAILED</strong></p>";
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    
    if (strpos($e->getMessage(), 'donor_type') !== false) {
        echo "<p><strong>Problem:</strong> Column 'donor_type' is missing. See Step 4 above for the fix.</p>";
    }
    if (strpos($e->getMessage(), 'agent_id') !== false) {
        echo "<p><strong>Problem:</strong> Column 'agent_id' is missing. See Step 4 above for the fix.</p>";
    }
    echo "</div>";
}
echo "</div>";

echo "<div class='box success'>";
echo "<h3>✅ Diagnostic Complete</h3>";
echo "<p>If you see any errors above, fix them using the provided SQL, then refresh this page.</p>";
echo "<p><a href='assign-donors.php' style='display: inline-block; background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin-top: 10px;'>← Back to Assign Donors</a></p>";
echo "</div>";

echo "</body></html>";
?>

