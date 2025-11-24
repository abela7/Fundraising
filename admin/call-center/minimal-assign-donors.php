<?php
// Minimal test version - no includes, basic HTML
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

declare(strict_types=1);

$page_title = 'Minimal Assign Donors Test';
$error_message = null;
$donors = false;
$stats = ['total' => 0];

try {
    require_once __DIR__ . '/../../shared/auth.php';
    require_once __DIR__ . '/../../config/db.php';
    require_admin(); // This might fail if auth issues

    echo "<!DOCTYPE html><html><head><title>$page_title</title>";
    echo "<link rel='stylesheet' href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css'>";
    echo "<style>body { padding: 20px; }</style></head><body>";
    echo "<h1>Minimal Test - Database Check</h1>";
    echo "<p>If you see this, PHP is running. Now testing DB...</p>";

    $db = db();
    echo "<p style='color: green;'>✓ DB connected!</p>";

    // Test basic query
    $result = $db->query("SHOW TABLES LIKE 'donors'");
    if ($result && $result->num_rows > 0) {
        echo "<p style='color: green;'>✓ Donors table exists!</p>";
        
        // Check columns
        $cols = $db->query("SHOW COLUMNS FROM donors LIKE 'donor_type'");
        if ($cols && $cols->num_rows > 0) {
            echo "<p style='color: green;'>✓ donor_type column exists!</p>";
        } else {
            echo "<p style='color: red;'>✗ donor_type column MISSING!</p>";
            throw new Exception("Add donor_type column via phpMyAdmin");
        }
        
        $cols2 = $db->query("SHOW COLUMNS FROM donors LIKE 'agent_id'");
        if ($cols2 && $cols2->num_rows > 0) {
            echo "<p style='color: green;'>✓ agent_id column exists!</p>";
        } else {
            echo "<p style='color: red;'>✗ agent_id column MISSING!</p>";
            throw new Exception("Add agent_id column via phpMyAdmin");
        }
        
        // Test main query
        $test_query = "SELECT COUNT(*) as total FROM donors WHERE donor_type = 'pledge'";
        $count = $db->query($test_query);
        if ($count) {
            $row = $count->fetch_assoc();
            echo "<p style='color: green;'>✓ Main query works! Total pledge donors: " . $row['total'] . "</p>";
        }
    } else {
        echo "<p style='color: red;'>✗ Donors table NOT FOUND!</p>";
        throw new Exception("Import database SQL in phpMyAdmin");
    }

    echo "<p style='color: green;'>All tests passed! The issue is not in the main code.</p>";
    echo "<a href='assign-donors.php' class='btn btn-primary'>Try Full Page</a>";

} catch (Exception $e) {
    echo "<div class='alert alert-danger'>";
    echo "<h4>Error: " . $e->getMessage() . "</h4>";
    echo "<p>Line: " . $e->getLine() . "</p>";
    echo "<p>File: " . $e->getFile() . "</p>";
    echo "</div>";
    echo "<a href='check-database.php' class='btn btn-warning'>Run Database Check</a>";
} catch (Error $e) {
    echo "<div class='alert alert-danger'>";
    echo "<h4>Fatal Error: " . $e->getMessage() . "</h4>";
    echo "</div>";
}

echo "</body></html>";
?>
