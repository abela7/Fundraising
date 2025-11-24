<?php
/**
 * Quick Database Column Checker
 * Run this to see if donor_type and agent_id columns exist
 */

require_once __DIR__ . '/../../config/db.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== CHECKING DATABASE COLUMNS ===\n\n";

try {
    $db = db();
    echo "✓ Database connected\n\n";
    
    // Get all columns from donors table
    echo "Checking 'donors' table columns...\n";
    echo str_repeat("-", 50) . "\n";
    
    $result = $db->query("SHOW COLUMNS FROM donors");
    
    $columns = [];
    while ($col = $result->fetch_assoc()) {
        $columns[] = $col['Field'];
        echo sprintf("%-30s %s\n", $col['Field'], $col['Type']);
    }
    
    echo str_repeat("-", 50) . "\n";
    echo "Total columns: " . count($columns) . "\n\n";
    
    // Check for required columns
    echo "=== REQUIRED COLUMNS CHECK ===\n\n";
    
    $required = [
        'donor_type' => 'ENUM for pledge/immediate_payment classification',
        'agent_id' => 'INT to assign agents to donors',
        'church_id' => 'INT to assign church',
        'balance' => 'Current outstanding balance',
        'total_pledged' => 'Total pledge amount',
        'payment_status' => 'Payment status tracking'
    ];
    
    $missing = [];
    foreach ($required as $col_name => $description) {
        $exists = in_array($col_name, $columns);
        $status = $exists ? "✓ EXISTS" : "✗ MISSING";
        echo sprintf("%-20s %s - %s\n", $col_name, $status, $description);
        
        if (!$exists) {
            $missing[] = $col_name;
        }
    }
    
    echo "\n";
    
    if (empty($missing)) {
        echo "✓✓✓ ALL REQUIRED COLUMNS EXIST ✓✓✓\n";
        echo "\nYour database is ready!\n";
        
        // Test a simple query
        echo "\n=== TESTING QUERY ===\n\n";
        $test = $db->query("SELECT COUNT(*) as total, 
            SUM(CASE WHEN donor_type = 'pledge' THEN 1 ELSE 0 END) as pledges,
            SUM(CASE WHEN agent_id IS NOT NULL THEN 1 ELSE 0 END) as assigned
            FROM donors");
        
        $data = $test->fetch_assoc();
        echo "Total donors: " . $data['total'] . "\n";
        echo "Pledge donors: " . $data['pledges'] . "\n";
        echo "Assigned to agents: " . $data['assigned'] . "\n";
        echo "\n✓ Query works perfectly!\n";
        
    } else {
        echo "✗✗✗ MISSING COLUMNS DETECTED ✗✗✗\n\n";
        echo "Missing: " . implode(', ', $missing) . "\n\n";
        
        echo "=== FIX SQL ===\n\n";
        echo "Run this SQL in phpMyAdmin:\n\n";
        echo str_repeat("=", 60) . "\n";
        
        if (in_array('donor_type', $missing)) {
            echo "-- Add donor_type column\n";
            echo "ALTER TABLE donors ADD COLUMN donor_type ENUM('immediate_payment', 'pledge') NOT NULL DEFAULT 'immediate_payment' AFTER id;\n";
            echo "UPDATE donors SET donor_type = CASE WHEN total_pledged > 0 THEN 'pledge' ELSE 'immediate_payment' END;\n";
            echo "ALTER TABLE donors ADD INDEX idx_donor_type (donor_type);\n\n";
        }
        
        if (in_array('agent_id', $missing)) {
            echo "-- Add agent_id column\n";
            echo "ALTER TABLE donors ADD COLUMN agent_id INT NULL COMMENT 'Agent assigned to donor';\n";
            echo "ALTER TABLE donors ADD INDEX idx_agent (agent_id);\n\n";
        }
        
        if (in_array('church_id', $missing)) {
            echo "-- Add church_id column\n";
            echo "ALTER TABLE donors ADD COLUMN church_id INT NULL COMMENT 'Church assignment';\n";
            echo "ALTER TABLE donors ADD INDEX idx_church (church_id);\n\n";
        }
        
        echo str_repeat("=", 60) . "\n";
    }
    
} catch (Exception $e) {
    echo "✗ ERROR: " . $e->getMessage() . "\n\n";
    
    if (strpos($e->getMessage(), 'Connection refused') !== false) {
        echo "Problem: MySQL is not running\n";
        echo "Fix: Start MySQL in XAMPP Control Panel\n";
    } elseif (strpos($e->getMessage(), 'Access denied') !== false) {
        echo "Problem: Wrong database credentials\n";
        echo "Fix: Check config/env.php\n";
    } elseif (strpos($e->getMessage(), "Table 'donors' doesn't exist") !== false) {
        echo "Problem: donors table doesn't exist\n";
        echo "Fix: Import your database SQL file in phpMyAdmin\n";
    }
}

echo "\n";
?>

