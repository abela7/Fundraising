<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

function db(): mysqli {
    static $conn = null;
    if ($conn === null) {
        try {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); // Enable exceptions
            $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            $conn->set_charset('utf8mb4');
        } catch (Exception $e) {
            // If connection fails, we need to handle it gracefully instead of crashing.
            // We'll throw a custom exception that our resilient loader can catch.
            throw new \RuntimeException('DB connection failed: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }
    return $conn;
}
