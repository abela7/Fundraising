<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

/**
 * Application-Level Connection Management (No SUPER privileges required)
 * 
 * Features:
 * - Smart connection reuse within same request
 * - Connection health monitoring
 * - Application-level rate limiting
 * - Quick connection failure detection
 * - Automatic connection cleanup
 */
function db(): mysqli {
    static $conn = null;
    static $lastCheck = 0;
    static $connectionCount = 0;
    static $requestStartTime = null;
    
    // Initialize request start time
    if ($requestStartTime === null) {
        $requestStartTime = microtime(true);
    }
    
    $now = time();
    $requestAge = microtime(true) - $requestStartTime;
    
    // Application-level connection limit: max 2 connections per request
    if ($connectionCount >= 2) {
        if ($conn instanceof mysqli && $conn->ping()) {
            return $conn; // Reuse existing connection
        }
    }
    
    // Auto-cleanup connections older than 25 seconds to prevent timeouts
    if ($requestAge > 25 && $conn instanceof mysqli) {
        @$conn->close();
        $conn = null;
        $lastCheck = 0;
    }
    
    // Check connection health every 5 seconds
    $needsCheck = ($now - $lastCheck) > 5;
    
    // If connection exists and is recent, return it
    if ($conn instanceof mysqli && !$needsCheck) {
        return $conn;
    }
    
    // Health check existing connection
    if ($conn instanceof mysqli && $needsCheck) {
        if (@$conn->ping()) {
            $lastCheck = $now;
            return $conn;
        } else {
            // Connection is dead, clean up
            @$conn->close();
            $conn = null;
        }
    }
    
    // Create new connection with built-in timeouts (no SUPER privileges needed)
    try {
        $connectionCount++;
        
        // Use mysqli_init for better timeout control
        $conn = mysqli_init();
        
        // Set client options (these don't require server privileges)
        $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 15);  // 15 second connect timeout
        $conn->options(MYSQLI_OPT_READ_TIMEOUT, 20);     // 20 second read timeout
        
        // Connect with timeout
        if (!$conn->real_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME)) {
            throw new RuntimeException('Connection failed: ' . $conn->connect_error);
        }
        
        // Set charset and other options
        $conn->set_charset('utf8mb4');
        
        // Verify connection works
        if (!$conn->ping()) {
            throw new RuntimeException('Connection ping failed');
        }
        
        $lastCheck = $now;
        return $conn;
        
    } catch (Exception $e) {
        // Single retry with exponential backoff
        $retryDelay = min(500000, 100000 * $connectionCount); // 0.1s to 0.5s
        usleep($retryDelay);
        
        try {
            $conn = mysqli_init();
            $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);  // Shorter timeout on retry
            $conn->options(MYSQLI_OPT_READ_TIMEOUT, 15);
            
            if (!$conn->real_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME)) {
                throw new RuntimeException('Retry connection failed: ' . $conn->connect_error);
            }
            
            $conn->set_charset('utf8mb4');
            $lastCheck = $now;
            return $conn;
            
        } catch (Exception $retryException) {
            error_log("DB connection failed after retry: " . $retryException->getMessage());
            
            // User-friendly error message
            throw new RuntimeException('Database temporarily busy. Please wait a moment and try again.');
        }
    }
}

/**
 * Close database connection explicitly when done
 * Call this at the end of long-running scripts
 */
function db_close(): void {
    static $conn;
    if ($conn instanceof mysqli) {
        $conn->close();
        $conn = null;
    }
}

/**
 * Get connection status for monitoring (No SUPER privileges required)
 */
function db_status(): array {
    try {
        $conn = db();
        
        // Get connection info that doesn't require privileges
        $status = [
            'connected' => true,
            'thread_id' => $conn->thread_id,
            'server_info' => $conn->server_info,
            'client_info' => $conn->client_info,
            'ping' => $conn->ping(),
            'charset' => $conn->character_set_name(),
        ];
        
        // Try to get some status variables (these usually work)
        try {
            $result = $conn->query("SHOW STATUS LIKE 'Threads_connected'");
            if ($result && $row = $result->fetch_assoc()) {
                $status['threads_connected'] = (int)$row['Value'];
            }
        } catch (Exception $e) {
            // Ignore if we can't get this info
        }
        
        return $status;
        
    } catch (Exception $e) {
        return [
            'connected' => false,
            'error' => $e->getMessage()
        ];
    }
}
