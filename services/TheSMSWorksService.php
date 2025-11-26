<?php
/**
 * The SMS Works Service
 * 
 * Handles all SMS operations via The SMS Works REST API
 * API Documentation: https://thesmsworks.co.uk/developers
 * 
 * @author Fundraising System
 */

declare(strict_types=1);

class TheSMSWorksService
{
    // API Endpoints
    private const API_BASE_URL = 'https://api.thesmsworks.co.uk/v1/';
    
    private string $apiKey;
    private string $apiSecret;
    private string $senderId;
    private ?int $providerId;
    private ?string $jwtToken = null;
    private $db;
    
    /**
     * Constructor
     * 
     * @param string $apiKey The SMS Works Customer ID
     * @param string $apiSecret The SMS Works API Key (Secret)
     * @param string $senderId Sender ID (max 11 alphanumeric chars)
     * @param mysqli|null $db Database connection for logging
     * @param int|null $providerId Provider ID in database
     */
    public function __construct(
        string $apiKey, 
        string $apiSecret, 
        string $senderId = 'ATEOTC',
        $db = null,
        ?int $providerId = null
    ) {
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
        $this->senderId = substr($senderId, 0, 11); // Max 11 chars
        $this->db = $db;
        $this->providerId = $providerId;
    }
    
    /**
     * Create instance from database provider settings
     * 
     * @param mysqli $db Database connection
     * @return self|null Returns null if no active provider found
     */
    public static function fromDatabase($db): ?self
    {
        try {
            // Check if table exists
            $check = $db->query("SHOW TABLES LIKE 'sms_providers'");
            if (!$check || $check->num_rows === 0) {
                return null;
            }
            
            // Get active The SMS Works provider
            $result = $db->query("
                SELECT id, api_key, api_secret, sender_id 
                FROM sms_providers 
                WHERE name = 'thesmsworks' AND is_active = 1 
                ORDER BY is_default DESC 
                LIMIT 1
            ");
            
            if (!$result || $result->num_rows === 0) {
                return null;
            }
            
            $provider = $result->fetch_assoc();
            
            return new self(
                $provider['api_key'],
                $provider['api_secret'],
                $provider['sender_id'] ?: 'ATEOTC',
                $db,
                (int)$provider['id']
            );
        } catch (Exception $e) {
            error_log("TheSMSWorks: Failed to load from database - " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get JWT token for API authentication
     * 
     * @return string|null JWT token or null on failure
     */
    private function getJwtToken(): ?string
    {
        if ($this->jwtToken !== null) {
            return $this->jwtToken;
        }
        
        $payload = [
            'customerid' => $this->apiKey,
            'key' => $this->apiSecret
        ];
        
        $response = $this->makeRequest('auth/token', $payload, 'POST', false);
        
        if ($response === false) {
            error_log("TheSMSWorks: Failed to get JWT token - connection error");
            return null;
        }
        
        $data = json_decode($response, true);
        
        if (isset($data['token'])) {
            $this->jwtToken = $data['token'];
            return $this->jwtToken;
        }
        
        error_log("TheSMSWorks: Failed to get JWT token - " . ($data['message'] ?? 'Unknown error'));
        return null;
    }
    
    /**
     * Send SMS message
     * 
     * @param string $phoneNumber Recipient phone number (UK format)
     * @param string $message Message content (max 918 chars for 6 segments)
     * @param array $options Additional options (donor_id, template_id, source_type, etc.)
     * @return array Result with 'success', 'message_id', 'error', 'credits_used'
     */
    public function send(string $phoneNumber, string $message, array $options = []): array
    {
        $startTime = microtime(true);
        
        // Normalize phone number to international format
        $phoneNumber = $this->normalizePhoneNumber($phoneNumber);
        
        if (!$phoneNumber) {
            return [
                'success' => false,
                'error' => 'Invalid phone number format',
                'error_code' => 'INVALID_PHONE'
            ];
        }
        
        // Check message length
        $messageLength = mb_strlen($message);
        if ($messageLength > 918) {
            return [
                'success' => false,
                'error' => 'Message too long (max 918 characters)',
                'error_code' => 'MESSAGE_TOO_LONG'
            ];
        }
        
        // Calculate credits (segments)
        $creditsUsed = $this->calculateCredits($message);
        
        // Build API request
        $payload = [
            'sender' => $this->senderId,
            'destination' => $phoneNumber,
            'content' => $message,
            'schedule' => '' // Empty for immediate send
        ];
        
        // Add scheduling if provided
        if (!empty($options['scheduled_for'])) {
            $payload['schedule'] = date('c', strtotime($options['scheduled_for']));
        }
        
        // Add metadata tag for tracking
        if (!empty($options['donor_id'])) {
            $payload['tag'] = 'donor_' . $options['donor_id'];
        }
        
        // Make API request
        $response = $this->makeRequest('message/send', $payload);
        $duration = round((microtime(true) - $startTime) * 1000);
        
        // Parse response
        $result = $this->parseResponse($response);
        
        // Log to database if connection available
        if ($this->db && !empty($options['log'])) {
            $this->logSMS(
                $phoneNumber,
                $message,
                $result['success'] ? 'sent' : 'failed',
                $result['message_id'] ?? null,
                $result['error'] ?? null,
                $creditsUsed,
                $options
            );
        }
        
        // Update provider stats
        if ($this->db && $this->providerId) {
            $this->updateProviderStats($result['success']);
        }
        
        return array_merge($result, [
            'credits_used' => $creditsUsed,
            'duration_ms' => $duration,
            'phone_number' => $phoneNumber
        ]);
    }
    
    /**
     * Send SMS to multiple recipients (batch)
     * 
     * @param array $recipients Array of ['phone' => '...', 'message' => '...', 'options' => [...]]
     * @return array Results for each recipient
     */
    public function sendBatch(array $recipients): array
    {
        $results = [];
        
        foreach ($recipients as $index => $recipient) {
            $phone = $recipient['phone'] ?? '';
            $message = $recipient['message'] ?? '';
            $options = $recipient['options'] ?? [];
            $options['log'] = true;
            
            $results[$index] = $this->send($phone, $message, $options);
            
            // Small delay to avoid rate limiting
            usleep(100000); // 100ms
        }
        
        return $results;
    }
    
    /**
     * Get account credit balance
     * 
     * @return array ['success' => bool, 'credits' => float, 'error' => string]
     */
    public function getBalance(): array
    {
        $response = $this->makeRequest('credits/balance', [], 'GET');
        
        if ($response === false) {
            return [
                'success' => false,
                'credits' => 0,
                'error' => 'Failed to connect to The SMS Works API'
            ];
        }
        
        $data = json_decode($response, true);
        
        if (isset($data['credits'])) {
            return [
                'success' => true,
                'credits' => (float)$data['credits'],
                'error' => null
            ];
        }
        
        return [
            'success' => false,
            'credits' => 0,
            'error' => $data['message'] ?? 'Unknown error'
        ];
    }
    
    /**
     * Test connection and credentials
     * 
     * @return array ['success' => bool, 'message' => string, 'credits' => float]
     */
    public function testConnection(): array
    {
        // First test getting a token
        $token = $this->getJwtToken();
        
        if (!$token) {
            return [
                'success' => false,
                'message' => 'Failed to authenticate. Check your Customer ID and API Key.',
                'credits' => 0
            ];
        }
        
        // Then try to get balance
        $balance = $this->getBalance();
        
        if ($balance['success']) {
            return [
                'success' => true,
                'message' => 'Connection successful! Credits available: ' . number_format($balance['credits'], 2),
                'credits' => $balance['credits']
            ];
        }
        
        return [
            'success' => false,
            'message' => 'Connection failed: ' . ($balance['error'] ?? 'Unknown error'),
            'credits' => 0
        ];
    }
    
    /**
     * Normalize phone number to international format
     * 
     * @param string $phone Phone number in any UK format
     * @return string|null Normalized number or null if invalid
     */
    private function normalizePhoneNumber(string $phone): ?string
    {
        // Remove all non-numeric characters except +
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        
        // Handle different formats
        if (preg_match('/^\+44(\d{10})$/', $phone, $matches)) {
            // Already international format +44...
            return '44' . $matches[1];
        }
        
        if (preg_match('/^44(\d{10})$/', $phone)) {
            // International without +
            return $phone;
        }
        
        if (preg_match('/^0([1-9]\d{9})$/', $phone, $matches)) {
            // UK national format 07xxx or 01xxx
            return '44' . $matches[1];
        }
        
        if (preg_match('/^([1-9]\d{9})$/', $phone)) {
            // 10 digit without leading 0
            return '44' . $phone;
        }
        
        // Invalid format
        return null;
    }
    
    /**
     * Calculate SMS credits (segments) required
     * 
     * @param string $message Message content
     * @return int Number of credits/segments
     */
    private function calculateCredits(string $message): int
    {
        $length = mb_strlen($message);
        
        // Check if message contains non-GSM characters (Unicode)
        $isUnicode = preg_match('/[^\x00-\x7F]/', $message);
        
        if ($isUnicode) {
            // Unicode: 70 chars first segment, 67 chars subsequent
            if ($length <= 70) return 1;
            return (int)ceil($length / 67);
        } else {
            // GSM-7: 160 chars first segment, 153 chars subsequent
            if ($length <= 160) return 1;
            return (int)ceil($length / 153);
        }
    }
    
    /**
     * Make HTTP request to The SMS Works API
     * 
     * @param string $endpoint API endpoint
     * @param array $payload Request payload
     * @param string $method HTTP method
     * @param bool $requireAuth Whether to include JWT token
     * @return string|false Response body or false on failure
     */
    private function makeRequest(string $endpoint, array $payload, string $method = 'POST', bool $requireAuth = true)
    {
        $url = self::API_BASE_URL . $endpoint;
        
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        
        // Add JWT token if required
        if ($requireAuth) {
            $token = $this->getJwtToken();
            if (!$token) {
                return false;
            }
            $headers[] = 'Authorization: ' . $token;
        }
        
        $ch = curl_init();
        
        $curlOptions = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => $headers
        ];
        
        if ($method === 'POST') {
            $curlOptions[CURLOPT_POST] = true;
            $curlOptions[CURLOPT_POSTFIELDS] = json_encode($payload);
        } elseif ($method === 'GET') {
            $curlOptions[CURLOPT_HTTPGET] = true;
            if (!empty($payload)) {
                $curlOptions[CURLOPT_URL] = $url . '?' . http_build_query($payload);
            }
        }
        
        curl_setopt_array($ch, $curlOptions);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($error) {
            error_log("TheSMSWorks cURL error: $error");
            return false;
        }
        
        if ($httpCode >= 400) {
            error_log("TheSMSWorks HTTP error: $httpCode - $response");
            // Still return response for error parsing
            return $response;
        }
        
        return $response;
    }
    
    /**
     * Parse API response
     * 
     * @param string|false $response Raw API response
     * @return array Parsed result
     */
    private function parseResponse($response): array
    {
        if ($response === false) {
            return [
                'success' => false,
                'error' => 'Failed to connect to The SMS Works API',
                'error_code' => 'CONNECTION_FAILED'
            ];
        }
        
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'error' => 'Invalid API response: ' . substr($response, 0, 100),
                'error_code' => 'INVALID_RESPONSE'
            ];
        }
        
        // Success response - check for messageid
        if (isset($data['messageid'])) {
            return [
                'success' => true,
                'message_id' => $data['messageid'],
                'error' => null
            ];
        }
        
        // Alternative success check
        if (isset($data['status']) && $data['status'] === 'SENT') {
            return [
                'success' => true,
                'message_id' => $data['id'] ?? null,
                'error' => null
            ];
        }
        
        // Error response
        return [
            'success' => false,
            'error' => $data['message'] ?? $data['error'] ?? 'Unknown error',
            'error_code' => $data['errorCode'] ?? 'UNKNOWN'
        ];
    }
    
    /**
     * Log SMS to database
     */
    private function logSMS(
        string $phoneNumber,
        string $message,
        string $status,
        ?string $messageId,
        ?string $error,
        int $credits,
        array $options
    ): void {
        try {
            // Check if sms_log table exists
            $check = $this->db->query("SHOW TABLES LIKE 'sms_log'");
            if (!$check || $check->num_rows === 0) {
                return;
            }
            
            $donorId = $options['donor_id'] ?? null;
            $templateId = $options['template_id'] ?? null;
            $sourceType = $options['source_type'] ?? 'api';
            $language = $options['language'] ?? 'en';
            
            // Get cost per SMS from provider settings (default 2.9p for The SMS Works)
            $costPence = $credits * 2.9;
            if ($this->providerId) {
                $costResult = $this->db->query("SELECT cost_per_sms_pence FROM sms_providers WHERE id = {$this->providerId}");
                if ($costResult && $row = $costResult->fetch_assoc()) {
                    $costPence = $credits * (float)$row['cost_per_sms_pence'];
                }
            }
            
            $stmt = $this->db->prepare("
                INSERT INTO sms_log 
                (donor_id, phone_number, template_id, message_content, message_language,
                 provider_id, provider_message_id, status, error_message, segments,
                 cost_pence, source_type, sent_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            if ($stmt) {
                $stmt->bind_param(
                    'isissississs',
                    $donorId,
                    $phoneNumber,
                    $templateId,
                    $message,
                    $language,
                    $this->providerId,
                    $messageId,
                    $status,
                    $error,
                    $credits,
                    $costPence,
                    $sourceType
                );
                $stmt->execute();
            }
        } catch (Exception $e) {
            error_log("TheSMSWorks: Failed to log SMS - " . $e->getMessage());
        }
    }
    
    /**
     * Update provider statistics
     */
    private function updateProviderStats(bool $success): void
    {
        try {
            if ($success) {
                $this->db->query("
                    UPDATE sms_providers 
                    SET last_success_at = NOW(), 
                        failure_count = 0,
                        updated_at = NOW()
                    WHERE id = {$this->providerId}
                ");
            } else {
                $this->db->query("
                    UPDATE sms_providers 
                    SET failure_count = failure_count + 1,
                        updated_at = NOW()
                    WHERE id = {$this->providerId}
                ");
            }
        } catch (Exception $e) {
            error_log("TheSMSWorks: Failed to update provider stats - " . $e->getMessage());
        }
    }
    
    /**
     * Replace template variables in message
     * 
     * @param string $template Message template with {variables}
     * @param array $data Key-value pairs for replacement
     * @return string Processed message
     */
    public static function processTemplate(string $template, array $data): string
    {
        foreach ($data as $key => $value) {
            $template = str_replace('{' . $key . '}', (string)$value, $template);
        }
        
        // Remove any unreplaced variables
        $template = preg_replace('/\{[a-z_]+\}/', '', $template);
        
        return trim($template);
    }
}

