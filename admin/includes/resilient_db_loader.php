<?php
declare(strict_types=1);

// --- Resilient Database Loading ---
// This script is included in admin pages that need initial DB data.
// It prevents fatal errors when tables are missing (e.g., after a DB wipe).

$settings = [];
$countersRow = [];
$db_error_message = '';
$db_connection_ok = false;

try {
    $db = db(); // db() function is from config/db.php
    $db_connection_ok = true;
    
    // Check if tables exist before attempting to query them
    $settings_table_exists = $db->query("SHOW TABLES LIKE 'settings'")->num_rows > 0;
    $counters_table_exists = $db->query("SHOW TABLES LIKE 'counters'")->num_rows > 0;

    if ($settings_table_exists) {
        // Fetch all settings, not just a few columns
        $settings = $db->query('SELECT * FROM settings WHERE id = 1')->fetch_assoc() ?: [];
    } else {
        $db_error_message .= '`settings` table not found. ';
    }

    if ($counters_table_exists) {
        $countersRow = $db->query("SELECT * FROM counters WHERE id = 1")->fetch_assoc() ?: [];
    } else {
        $db_error_message .= '`counters` table not found. ';
    }

} catch (Exception $e) {
    // This catches DB connection errors
    $db_error_message = 'Database connection failed: ' . $e->getMessage();
}
// --- End Resilient Loading ---
