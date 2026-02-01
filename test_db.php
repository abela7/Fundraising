<?php
require_once 'config/db.php';

echo "Testing database connection...\n";

try {
    $db = db();
    echo "✓ Database connection: SUCCESS\n";
    echo "✓ Database name: " . $db->get_server_info() . "\n";
    
    // Test basic query
    $result = $db->query("SELECT COUNT(*) as count FROM counters WHERE id=1");
    if ($result) {
        $row = $result->fetch_assoc();
        echo "✓ Database query test: SUCCESS\n";
        echo "✓ Counters table accessible\n";
    } else {
        echo "✗ Database query test: FAILED\n";
    }
    
} catch (Exception $e) {
    echo "✗ Database connection: FAILED\n";
    echo "Error: " . $e->getMessage() . "\n";
}
?>
