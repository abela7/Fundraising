<?php
/**
 * Fix twilio_inbound_calls table - Add missing columns
 * DELETE THIS FILE AFTER RUNNING
 */

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

require_login();
require_admin();

$db = db();

echo "<h1>Fixing twilio_inbound_calls Table</h1>";
echo "<pre style='background:#1a1a2e;color:#eee;padding:20px;border-radius:8px;'>";

// Check current columns
$result = $db->query("SHOW COLUMNS FROM twilio_inbound_calls");
$existing_columns = [];
while ($row = $result->fetch_assoc()) {
    $existing_columns[] = $row['Field'];
}

echo "Current columns: " . implode(', ', $existing_columns) . "\n\n";

// Columns that should exist
$required_columns = [
    'is_donor' => "ALTER TABLE twilio_inbound_calls ADD COLUMN `is_donor` TINYINT(1) DEFAULT 0 AFTER `donor_name`",
    'menu_selection' => "ALTER TABLE twilio_inbound_calls ADD COLUMN `menu_selection` VARCHAR(50) NULL AFTER `is_donor`",
    'payment_method' => "ALTER TABLE twilio_inbound_calls ADD COLUMN `payment_method` VARCHAR(20) NULL AFTER `menu_selection`",
    'payment_amount' => "ALTER TABLE twilio_inbound_calls ADD COLUMN `payment_amount` DECIMAL(10,2) NULL AFTER `payment_method`",
    'payment_status' => "ALTER TABLE twilio_inbound_calls ADD COLUMN `payment_status` ENUM('none','pending','confirmed','failed') DEFAULT 'none' AFTER `payment_amount`",
    'call_duration' => "ALTER TABLE twilio_inbound_calls ADD COLUMN `call_duration` INT NULL AFTER `payment_status`",
    'call_status' => "ALTER TABLE twilio_inbound_calls ADD COLUMN `call_status` VARCHAR(50) NULL AFTER `call_duration`",
    'whatsapp_sent' => "ALTER TABLE twilio_inbound_calls ADD COLUMN `whatsapp_sent` TINYINT(1) DEFAULT 0 AFTER `call_status`",
    'sms_sent' => "ALTER TABLE twilio_inbound_calls ADD COLUMN `sms_sent` TINYINT(1) DEFAULT 0 AFTER `whatsapp_sent`",
    'agent_followed_up' => "ALTER TABLE twilio_inbound_calls ADD COLUMN `agent_followed_up` TINYINT(1) DEFAULT 0 AFTER `sms_sent`",
    'followed_up_by' => "ALTER TABLE twilio_inbound_calls ADD COLUMN `followed_up_by` INT NULL AFTER `agent_followed_up`",
    'followed_up_at' => "ALTER TABLE twilio_inbound_calls ADD COLUMN `followed_up_at` DATETIME NULL AFTER `followed_up_by`",
    'notes' => "ALTER TABLE twilio_inbound_calls ADD COLUMN `notes` TEXT NULL AFTER `followed_up_at`",
];

$added = 0;
$skipped = 0;

foreach ($required_columns as $column => $sql) {
    if (in_array($column, $existing_columns)) {
        echo "✓ Column '$column' already exists - SKIPPED\n";
        $skipped++;
    } else {
        echo "Adding column '$column'... ";
        if ($db->query($sql)) {
            echo "✅ SUCCESS\n";
            $added++;
        } else {
            echo "❌ FAILED: " . $db->error . "\n";
        }
    }
}

// Update existing records to set is_donor based on donor_id
echo "\nUpdating is_donor flag for existing records... ";
$update = $db->query("UPDATE twilio_inbound_calls SET is_donor = 1 WHERE donor_id IS NOT NULL AND is_donor = 0");
if ($update) {
    echo "✅ SUCCESS (affected: " . $db->affected_rows . " rows)\n";
} else {
    echo "❌ FAILED: " . $db->error . "\n";
}

// Add indexes if missing
echo "\nChecking indexes...\n";
$indexes = [];
$indexResult = $db->query("SHOW INDEX FROM twilio_inbound_calls");
while ($row = $indexResult->fetch_assoc()) {
    $indexes[] = $row['Key_name'];
}

$required_indexes = [
    'idx_agent_followed_up' => "ALTER TABLE twilio_inbound_calls ADD INDEX `idx_agent_followed_up` (`agent_followed_up`)",
    'idx_is_donor' => "ALTER TABLE twilio_inbound_calls ADD INDEX `idx_is_donor` (`is_donor`)",
];

foreach ($required_indexes as $index => $sql) {
    if (in_array($index, $indexes)) {
        echo "✓ Index '$index' already exists\n";
    } else {
        echo "Adding index '$index'... ";
        if ($db->query($sql)) {
            echo "✅ SUCCESS\n";
        } else {
            echo "⚠️ " . $db->error . "\n";
        }
    }
}

echo "\n========================================\n";
echo "DONE! Added $added columns, skipped $skipped\n";
echo "========================================\n";

// Show final structure
echo "\nFinal table structure:\n";
$result = $db->query("SHOW COLUMNS FROM twilio_inbound_calls");
while ($row = $result->fetch_assoc()) {
    echo "  - " . $row['Field'] . " (" . $row['Type'] . ")\n";
}

echo "</pre>";

echo "<p style='color:green;font-size:18px;'>✅ <strong>Table fixed! Now try loading the <a href='inbound-callbacks.php'>Inbound Callbacks</a> page.</strong></p>";
echo "<p style='color:orange;'>⚠️ Delete this file (fix-inbound-table.php) after use!</p>";

