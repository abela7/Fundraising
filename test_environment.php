<?php
require_once 'config/env.php';

echo "🔍 Environment Detection Test\n";
echo "=============================\n\n";

echo "Environment: " . ENVIRONMENT . "\n";
echo "Database Host: " . DB_HOST . "\n";
echo "Database User: " . DB_USER . "\n";
echo "Database Name: " . DB_NAME . "\n\n";

echo "Server Variables:\n";
echo "HTTP_HOST: " . ($_SERVER['HTTP_HOST'] ?? 'Not set') . "\n";
echo "SERVER_NAME: " . ($_SERVER['SERVER_NAME'] ?? 'Not set') . "\n";
echo "DOCUMENT_ROOT: " . ($_SERVER['DOCUMENT_ROOT'] ?? 'Not set') . "\n\n";

echo "Local Environment Check: " . (isLocalEnvironment() ? 'YES' : 'NO') . "\n\n";

// Test database connection
try {
    require_once 'config/db.php';
    $db = db();
    echo "✅ Database connection: SUCCESS\n";
    echo "✅ Connected to: " . DB_NAME . "\n";
} catch (Exception $e) {
    echo "❌ Database connection: FAILED\n";
    echo "Error: " . $e->getMessage() . "\n";
    
    if (ENVIRONMENT === 'local') {
        echo "\n💡 To fix this:\n";
        echo "1. Open phpMyAdmin (http://localhost/phpmyadmin)\n";
        echo "2. Create database: " . DB_NAME . "\n";
        echo "3. Import your fundraising_DB.sql file\n";
    }
}
?>
