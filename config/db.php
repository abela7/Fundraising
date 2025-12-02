<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

// Set PHP timezone to London
date_default_timezone_set('Europe/London');

function db(): mysqli {
    static $conn = null;
    if ($conn === null) {
        try {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); // Enable exceptions
            $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            $conn->set_charset('utf8mb4');
            
            // Set MySQL session timezone to London
            // Get PHP's current offset and apply to MySQL
            $now = new DateTime('now', new DateTimeZone('Europe/London'));
            $offset = $now->format('P'); // e.g., "+00:00" or "+01:00" (BST)
            $conn->query("SET time_zone = '$offset'");
        } catch (Exception $e) {
            // If connection fails, we need to handle it gracefully instead of crashing.
            // We'll throw a custom exception that our resilient loader can catch.
            throw new \RuntimeException('DB connection failed: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }
    return $conn;
}
