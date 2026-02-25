<?php
declare(strict_types=1);

/**
 * Rate Limiter for Donation Forms
 * Protects against spam, DDOS, and abuse while keeping it functional for legitimate users
 */
class RateLimiter {
    private $db;
    private $tableName = 'rate_limits';
    
    // Rate limits configuration (relaxed to allow donors to register multiple times)
    private $limits = [
        // Per IP limits (spam protection only; generous for legitimate repeat donors)
        'ip_per_minute' => 10,     // Max 10 submissions per minute per IP
        'ip_per_hour' => 50,        // Max 50 submissions per hour per IP
        'ip_per_day' => 200,       // Max 200 submissions per day per IP
        
        // Per phone limits (allow multiple registrations; donors may submit many times)
        'phone_per_hour' => 20,    // Max 20 submissions per hour per phone
        'phone_per_day' => 100,    // Max 100 submissions per day per phone
        
        // Global limits (emergency brake)
        'global_per_minute' => 50, // Max 50 total submissions per minute
        'global_per_hour' => 500,  // Max 500 total submissions per hour
        
        // Suspicious activity thresholds
        'suspicious_threshold' => 5, // After 5 submissions, require CAPTCHA
        'block_threshold' => 15,     // After 15 submissions, temporary block
    ];
    
    public function __construct($database) {
        $this->db = $database;
        $this->createTableIfNotExists();
    }
    
    /**
     * Check if submission is allowed
     * Returns: ['allowed' => bool, 'reason' => string, 'retry_after' => int|null, 'require_captcha' => bool]
     */
    public function checkSubmission(string $ip, ?string $phone = null): array {
        $this->cleanOldRecords();
        
        // Check IP-based limits
        $ipCheck = $this->checkIpLimits($ip);
        if (!$ipCheck['allowed']) {
            return $ipCheck;
        }
        
        // Check phone-based limits (if phone provided)
        if ($phone) {
            $phoneCheck = $this->checkPhoneLimits($phone);
            if (!$phoneCheck['allowed']) {
                return $phoneCheck;
            }
        }
        
        // Check global limits
        $globalCheck = $this->checkGlobalLimits();
        if (!$globalCheck['allowed']) {
            return $globalCheck;
        }
        
        // Check if CAPTCHA should be required
        $requireCaptcha = $this->shouldRequireCaptcha($ip, $phone);
        
        return [
            'allowed' => true,
            'reason' => '',
            'retry_after' => null,
            'require_captcha' => $requireCaptcha
        ];
    }
    
    /**
     * Record a successful submission
     */
    public function recordSubmission(string $ip, ?string $phone = null, array $metadata = []): void {
        $stmt = $this->db->prepare("
            INSERT INTO {$this->tableName} 
            (ip_address, phone_number, submission_time, metadata, created_at) 
            VALUES (?, ?, NOW(), ?, NOW())
        ");
        
        $metadataJson = json_encode($metadata);
        $stmt->bind_param('sss', $ip, $phone, $metadataJson);
        $stmt->execute();
    }
    
    /**
     * Check if IP is currently blocked
     */
    public function isBlocked(string $ip): array {
        // Check for recent excessive submissions
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count, MAX(submission_time) as last_submission
            FROM {$this->tableName} 
            WHERE ip_address = ? AND submission_time >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $stmt->bind_param('s', $ip);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        if ($result['count'] >= $this->limits['block_threshold']) {
            $lastSubmission = strtotime($result['last_submission']);
            $blockUntil = $lastSubmission + (60 * 60); // Block for 1 hour
            
            if (time() < $blockUntil) {
                return [
                    'blocked' => true,
                    'reason' => 'Too many submission attempts. Please try again later.',
                    'retry_after' => $blockUntil - time()
                ];
            }
        }
        
        return ['blocked' => false];
    }
    
    /**
     * Get current submission stats for an IP
     */
    public function getIpStats(string $ip): array {
        $stats = [];
        
        // Count submissions in different time windows
        $windows = [
            'last_minute' => '1 MINUTE',
            'last_hour' => '1 HOUR', 
            'last_day' => '1 DAY'
        ];
        
        foreach ($windows as $key => $interval) {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count 
                FROM {$this->tableName} 
                WHERE ip_address = ? AND submission_time >= DATE_SUB(NOW(), INTERVAL {$interval})
            ");
            $stmt->bind_param('s', $ip);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stats[$key] = (int)$result['count'];
        }
        
        return $stats;
    }
    
    private function checkIpLimits(string $ip): array {
        $stats = $this->getIpStats($ip);
        
        // Check minute limit
        if ($stats['last_minute'] >= $this->limits['ip_per_minute']) {
            return [
                'allowed' => false,
                'reason' => 'Too many submissions in the last minute. Please wait before trying again.',
                'retry_after' => 60,
                'require_captcha' => false
            ];
        }
        
        // Check hour limit
        if ($stats['last_hour'] >= $this->limits['ip_per_hour']) {
            return [
                'allowed' => false,
                'reason' => 'Too many submissions in the last hour. Please try again later.',
                'retry_after' => 3600,
                'require_captcha' => false
            ];
        }
        
        // Check day limit
        if ($stats['last_day'] >= $this->limits['ip_per_day']) {
            return [
                'allowed' => false,
                'reason' => 'Daily submission limit reached. Please try again tomorrow.',
                'retry_after' => 86400,
                'require_captcha' => false
            ];
        }
        
        return ['allowed' => true, 'require_captcha' => false];
    }
    
    private function checkPhoneLimits(string $phone): array {
        // Check hour limit for phone
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count 
            FROM {$this->tableName} 
            WHERE phone_number = ? AND submission_time >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $stmt->bind_param('s', $phone);
        $stmt->execute();
        $hourCount = (int)$stmt->get_result()->fetch_assoc()['count'];
        
        if ($hourCount >= $this->limits['phone_per_hour']) {
            return [
                'allowed' => false,
                'reason' => 'Too many submissions from this phone number. Please try again later.',
                'retry_after' => 3600,
                'require_captcha' => false
            ];
        }
        
        // Check day limit for phone
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count 
            FROM {$this->tableName} 
            WHERE phone_number = ? AND submission_time >= DATE_SUB(NOW(), INTERVAL 1 DAY)
        ");
        $stmt->bind_param('s', $phone);
        $stmt->execute();
        $dayCount = (int)$stmt->get_result()->fetch_assoc()['count'];
        
        if ($dayCount >= $this->limits['phone_per_day']) {
            return [
                'allowed' => false,
                'reason' => 'Daily submission limit reached for this phone number.',
                'retry_after' => 86400,
                'require_captcha' => false
            ];
        }
        
        return ['allowed' => true, 'require_captcha' => false];
    }
    
    private function checkGlobalLimits(): array {
        // Check global minute limit
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count 
            FROM {$this->tableName} 
            WHERE submission_time >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)
        ");
        $stmt->execute();
        $minuteCount = (int)$stmt->get_result()->fetch_assoc()['count'];
        
        if ($minuteCount >= $this->limits['global_per_minute']) {
            return [
                'allowed' => false,
                'reason' => 'System is experiencing high traffic. Please try again in a few minutes.',
                'retry_after' => 300, // 5 minutes
                'require_captcha' => false
            ];
        }
        
        // Check global hour limit
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count 
            FROM {$this->tableName} 
            WHERE submission_time >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $stmt->execute();
        $hourCount = (int)$stmt->get_result()->fetch_assoc()['count'];
        
        if ($hourCount >= $this->limits['global_per_hour']) {
            return [
                'allowed' => false,
                'reason' => 'System is experiencing high traffic. Please try again later.',
                'retry_after' => 1800, // 30 minutes
                'require_captcha' => false
            ];
        }
        
        return ['allowed' => true, 'require_captcha' => false];
    }
    
    private function shouldRequireCaptcha(string $ip, ?string $phone = null): bool {
        $stats = $this->getIpStats($ip);
        
        // Require CAPTCHA after suspicious threshold
        if ($stats['last_hour'] >= $this->limits['suspicious_threshold']) {
            return true;
        }
        
        // Check phone-based suspicious activity
        if ($phone) {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count 
                FROM {$this->tableName} 
                WHERE phone_number = ? AND submission_time >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");
            $stmt->bind_param('s', $phone);
            $stmt->execute();
            $phoneCount = (int)$stmt->get_result()->fetch_assoc()['count'];
            
            if ($phoneCount >= 2) { // Require CAPTCHA after 2 submissions per hour per phone
                return true;
            }
        }
        
        return false;
    }
    
    private function cleanOldRecords(): void {
        // Clean records older than 7 days to keep table size manageable
        $this->db->query("
            DELETE FROM {$this->tableName} 
            WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
    }
    
    private function createTableIfNotExists(): void {
        $sql = "
            CREATE TABLE IF NOT EXISTS {$this->tableName} (
                id INT PRIMARY KEY AUTO_INCREMENT,
                ip_address VARCHAR(45) NOT NULL,
                phone_number VARCHAR(20) NULL,
                submission_time DATETIME NOT NULL,
                metadata JSON NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_ip_time (ip_address, submission_time),
                INDEX idx_phone_time (phone_number, submission_time),
                INDEX idx_submission_time (submission_time),
                INDEX idx_created_at (created_at)
            )
        ";
        $this->db->query($sql);
    }
    
    /**
     * Get user-friendly time remaining message
     */
    public static function formatRetryAfter(int $seconds): string {
        if ($seconds < 60) {
            return $seconds . ' seconds';
        } elseif ($seconds < 3600) {
            $minutes = ceil($seconds / 60);
            return $minutes . ' minute' . ($minutes > 1 ? 's' : '');
        } elseif ($seconds < 86400) {
            $hours = ceil($seconds / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '');
        } else {
            $days = ceil($seconds / 86400);
            return $days . ' day' . ($days > 1 ? 's' : '');
        }
    }
}
?>
