<?php
/**
 * VoodooSMS Service
 * 
 * Handles all SMS operations via VoodooSMS REST API
 * API Documentation: https://help.voodoosms.com/en/categories/15-rest-api
 * 
 * @author Fundraising System
 */

declare(strict_types=1);

class VoodooSMSService
{
    // API Endpoints
    private const API_BASE_URL = 'https://www.voodoosms.com/vapi/server/';
    private const SEND_ENDPOINT = 'sendSMS';
    private const BALANCE_ENDPOINT = 'getCredit';
    
    private string $apiKey;
    private string $apiSecret;
    private string $senderId;
    private ?int $providerId;
    private $db;
    
    /**
     * Constructor
     * 
     * @param string $apiKey VoodooSMS API Key (Username)
     * @param string $apiSecret VoodooSMS API Secret (Password)
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
            
            // Get active VoodooSMS provider
            $result = $db->query("
                SELECT id, api_key, api_secret, sender_id 
                FROM sms_providers 
                WHERE name = 'voodoosms' AND is_active = 1 
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
            error_log("VoodooSMS: Failed to load from database - " . $e->getMessage());
            return null;
        }
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
        $params = [
            'uid' => $this->apiKey,
            'pass' => $this->apiSecret,
            'dest' => $phoneNumber,
            'orig' => $this->senderId,
            'msg' => $message,
            'format' => 'json'
        ];
        
        // Add scheduling if provided
        if (!empty($options['scheduled_for'])) {
            $params['sd'] = date('Y-m-d\TH:i:s', strtotime($options['scheduled_for']));
        }
        
        // Make API request
        $response = $this->makeRequest(self::SEND_ENDPOINT, $params);
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
     * @return array ['success' => bool, 'credits' => int, 'error' => string]
     */
    public function getBalance(): array
    {
        $params = [
            'uid' => $this->apiKey,
            'pass' => $this->apiSecret,
            'format' => 'json'
        ];
        
        $response = $this->makeRequest(self::BALANCE_ENDPOINT, $params);
        
        if ($response === false) {
            return [
                'success' => false,
                'credits' => 0,
                'error' => 'Failed to connect to VoodooSMS API'
            ];
        }
        
        $parsed = $this->parseResponse($response);
        
        if (!$parsed['success']) {
            return [
                'success' => false,
                'credits' => 0,
                'error' => $parsed['error'] ?? 'Unknown error'
            ];
        }
        
        return [
            'success' => true,
            'credits' => (int)round((float)($parsed['credits'] ?? 0)),
            'error' => null
        ];
    }
    
    /**
     * Test connection and credentials
     * 
     * @return array ['success' => bool, 'message' => string, 'credits' => int]
     */
    public function testConnection(): array
    {
        $balance = $this->getBalance();
        
        if ($balance['success']) {
            return [
                'success' => true,
                'message' => 'Connection successful! Credits available: ' . $balance['credits'],
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
     * Make HTTP request to VoodooSMS API
     * 
     * @param string $endpoint API endpoint
     * @param array $params Request parameters
     * @return string|false Response body or false on failure
     */
    private function makeRequest(string $endpoint, array $params)
    {
        $url = self::API_BASE_URL . $endpoint;
        
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json'
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($error) {
            error_log("VoodooSMS cURL error: $error");
            return false;
        }
        
        if ($response === false || $response === '') {
            error_log("VoodooSMS request failed. HTTP code: $httpCode");
            return false;
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
                'error' => 'Failed to connect to VoodooSMS API',
                'error_code' => 'CONNECTION_FAILED'
            ];
        }
        
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Try XML response
            $xml = @simplexml_load_string($response);
            if ($xml !== false) {
                $parsedXml = json_decode(json_encode($xml), true);
                
                if (is_array($parsedXml)) {
                    $resultRaw = (string)($parsedXml['result'] ?? '');
                    $resultCode = (int)$this->extractResultCode($resultRaw);
                    
                    if ($resultCode === 200) {
                        return [
                            'success' => true,
                            'credits' => isset($parsedXml['credit']) ? (float)$parsedXml['credit'] : null,
                            'message_id' => $parsedXml['messageId'] ?? $parsedXml['reference_number'] ?? null,
                            'error' => null
                        ];
                    }
                    
                    return [
                        'success' => false,
                        'error' => $parsedXml['resultText'] ?? $resultRaw ?: 'Request failed',
                        'error_code' => $resultRaw ?: 'UNKNOWN'
                    ];
                }
            }
            
            // Try to parse as plain text response
            if (strpos($response, 'OK') === 0) {
                // Success response format: OK {reference_number}
                preg_match('/OK\s+(\S+)/', $response, $matches);
                return [
                    'success' => true,
                    'message_id' => $matches[1] ?? null,
                    'error' => null
                ];
            }
            
            return [
                'success' => false,
                'error' => 'Invalid API response: ' . substr($response, 0, 100),
                'error_code' => 'INVALID_RESPONSE'
            ];
        }
        
        // JSON response
        if (isset($data['result'])) {
            $resultCode = (int)$this->extractResultCode((string)$data['result']);
            
            if ($resultCode === 200) {
                return [
                    'success' => true,
                    'message_id' => $data['reference_number'] ?? $data['resultText'] ?? null,
                    'credits' => isset($data['credit']) ? (float)$data['credit'] : null,
                    'error' => null
                ];
            }

            return [
                'success' => false,
                'message_id' => null,
                'error' => $data['resultText'] ?? 'Request failed',
                'error_code' => $data['result'] ?? 'UNKNOWN'
            ];
        }
        
        // Error response
        return [
            'success' => false,
            'error' => $data['resultText'] ?? $data['error'] ?? 'Unknown error',
            'error_code' => $data['result'] ?? 'UNKNOWN'
        ];
    }
    
    /**
     * Extract numeric HTTP/API result code from mixed result strings
     */
    private function extractResultCode(string $resultRaw): int
    {
        $matches = [];
        if (preg_match('/^\s*(\d{3})\b/', trim($resultRaw), $matches)) {
            return (int)$matches[1];
        }
        
        return 0;
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
            
            // Get cost per SMS from provider settings (default 3.5p)
            $costPence = $credits * 3.5;
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
                // Types: i=donor_id, s=phone, i=template_id, s=message, s=language, 
                //        i=provider_id, s=message_id, s=status, s=error, i=segments, d=cost, s=source
                $stmt->bind_param(
                    'isissiissids',
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
            error_log("VoodooSMS: Failed to log SMS - " . $e->getMessage());
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
            error_log("VoodooSMS: Failed to update provider stats - " . $e->getMessage());
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

