<?php
/**
 * Twilio Diagnostic Page
 * Helps identify configuration issues
 */

declare(strict_types=1);

echo "<h1>Twilio System Diagnostic</h1>";
echo "<pre>";

// Test 1: PHP Version
echo "1. PHP Version: " . phpversion() . "\n";

// Test 2: MySQLi Extension
echo "2. MySQLi Extension: " . (extension_loaded('mysqli') ? 'LOADED ✓' : 'NOT LOADED ✗') . "\n";

// Test 3: Auth File
echo "3. Loading auth.php: ";
try {
    require_once __DIR__ . '/../../shared/auth.php';
    echo "SUCCESS ✓\n";
} catch (Exception $e) {
    echo "FAILED ✗ - " . $e->getMessage() . "\n";
}

// Test 4: Database
echo "4. Database Connection: ";
try {
    require_once __DIR__ . '/../../config/db.php';
    $db = db();
    echo "SUCCESS ✓\n";
} catch (Exception $e) {
    echo "FAILED ✗ - " . $e->getMessage() . "\n";
}

// Test 5: TwilioErrorCodes Class
echo "5. Loading TwilioErrorCodes: ";
try {
    require_once __DIR__ . '/../../services/TwilioErrorCodes.php';
    $test = TwilioErrorCodes::getErrorInfo('486');
    echo "SUCCESS ✓\n";
    echo "   Test Error Info: " . $test['category'] . "\n";
} catch (Exception $e) {
    echo "FAILED ✗ - " . $e->getMessage() . "\n";
}

// Test 6: Check Database Columns
echo "6. Checking Database Columns: ";
try {
    $result = $db->query("SHOW COLUMNS FROM call_center_sessions LIKE 'twilio_error_code'");
    if ($result && $result->num_rows > 0) {
        echo "SUCCESS ✓\n";
    } else {
        echo "FAILED ✗ - Column 'twilio_error_code' not found\n";
    }
} catch (Exception $e) {
    echo "FAILED ✗ - " . $e->getMessage() . "\n";
}

// Test 7: Sample Query
echo "7. Testing Sample Query: ";
try {
    $query = "SELECT COUNT(*) as cnt FROM call_center_sessions WHERE call_source = 'twilio'";
    $result = $db->query($query);
    if ($result) {
        $row = $result->fetch_assoc();
        echo "SUCCESS ✓ - Found " . $row['cnt'] . " Twilio calls\n";
    } else {
        echo "FAILED ✗ - " . $db->error . "\n";
    }
} catch (Exception $e) {
    echo "FAILED ✗ - " . $e->getMessage() . "\n";
}

// Test 8: Permissions
echo "8. File Permissions: ";
$file = __DIR__ . '/twilio-error-report.php';
echo (is_readable($file) ? 'READABLE ✓' : 'NOT READABLE ✗') . "\n";

// Test 9: PHP Errors
echo "9. PHP Error Reporting: ";
echo "Error Reporting Level: " . error_reporting() . "\n";
echo "   Display Errors: " . ini_get('display_errors') . "\n";

echo "\n";
echo "===========================================\n";
echo "All tests complete!\n";
echo "===========================================\n";
echo "</pre>";

// Show PHP Info (useful for debugging)
echo "<h2>Additional Info</h2>";
echo "<details><summary>Click to see phpinfo()</summary>";
phpinfo();
echo "</details>";
?>

