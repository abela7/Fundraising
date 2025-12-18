<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/ApiResponse.php';

/**
 * API Rate Limiter
 * 
 * Prevents abuse by limiting requests per IP/user.
 * Uses database-based tracking for persistence across requests.
 */
class ApiRateLimiter
{
    private mysqli $db;
    
    // Default limits
    private const DEFAULT_LIMIT = 100; // requests
    private const DEFAULT_WINDOW = 60; // seconds
    
    // Endpoint-specific limits
    private const LIMITS = [
        'auth/login' => ['limit' => 10, 'window' => 300], // 10 per 5 min
        'auth/otp/send' => ['limit' => 5, 'window' => 300], // 5 per 5 min
        'auth/otp/verify' => ['limit' => 10, 'window' => 300], // 10 per 5 min
        'pwa/install-log' => ['limit' => 10, 'window' => 60], // 10 per min
    ];

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?? db();
    }

    /**
     * Check rate limit for an endpoint
     * 
     * @param string $endpoint The API endpoint being accessed
     * @param string|null $identifier User ID or null for IP-based limiting
     * @return bool True if allowed, false if rate limited
     */
    public function check(string $endpoint, ?string $identifier = null): bool
    {
        $config = $this->getLimit($endpoint);
        $key = $this->getKey($endpoint, $identifier);
        
        // Clean old entries
        $this->cleanup();
        
        // Count recent requests
        $windowStart = date('Y-m-d H:i:s', time() - $config['window']);
        
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) as count FROM api_request_log 
             WHERE endpoint = ? AND ip_address = ? AND request_time >= ?"
        );
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $stmt->bind_param('sss', $endpoint, $ip, $windowStart);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        $count = (int) ($result['count'] ?? 0);
        
        // Set rate limit headers
        header('X-RateLimit-Limit: ' . $config['limit']);
        header('X-RateLimit-Remaining: ' . max(0, $config['limit'] - $count - 1));
        header('X-RateLimit-Reset: ' . (time() + $config['window']));
        
        if ($count >= $config['limit']) {
            header('Retry-After: ' . $config['window']);
            return false;
        }
        
        return true;
    }

    /**
     * Enforce rate limit - exits with 429 if exceeded
     */
    public function enforce(string $endpoint, ?string $identifier = null): void
    {
        if (!$this->check($endpoint, $identifier)) {
            $config = $this->getLimit($endpoint);
            ApiResponse::error(
                "Rate limit exceeded. Please try again in {$config['window']} seconds.",
                429,
                'RATE_LIMITED'
            );
        }
    }

    /**
     * Log an API request
     */
    public function logRequest(
        string $endpoint,
        string $method,
        ?string $userType = null,
        ?int $userId = null,
        ?int $responseCode = null,
        ?int $responseTimeMs = null,
        ?string $errorMessage = null
    ): void {
        $stmt = $this->db->prepare(
            "INSERT INTO api_request_log 
             (endpoint, method, user_type, user_id, ip_address, response_code, response_time_ms, error_message)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $stmt->bind_param(
            'sssisiis',
            $endpoint,
            $method,
            $userType,
            $userId,
            $ip,
            $responseCode,
            $responseTimeMs,
            $errorMessage
        );
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Get rate limit config for endpoint
     */
    private function getLimit(string $endpoint): array
    {
        // Check for exact match
        if (isset(self::LIMITS[$endpoint])) {
            return self::LIMITS[$endpoint];
        }
        
        // Check for prefix match
        foreach (self::LIMITS as $pattern => $config) {
            if (str_starts_with($endpoint, $pattern)) {
                return $config;
            }
        }
        
        return ['limit' => self::DEFAULT_LIMIT, 'window' => self::DEFAULT_WINDOW];
    }

    /**
     * Generate rate limit key
     */
    private function getKey(string $endpoint, ?string $identifier): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return $identifier ? "{$endpoint}:{$identifier}" : "{$endpoint}:{$ip}";
    }

    /**
     * Clean up old log entries
     */
    private function cleanup(): void
    {
        // Only run cleanup 1% of the time to reduce overhead
        if (mt_rand(1, 100) !== 1) {
            return;
        }
        
        // Delete entries older than 24 hours
        $this->db->query(
            "DELETE FROM api_request_log WHERE request_time < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
    }
}

