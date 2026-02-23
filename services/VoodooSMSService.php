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
    private ?array $lastRequestDebug = null;
    
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
        
        $this->lastRequestDebug = [
            'request' => [
                'method' => 'POST',
                'url' => self::API_BASE_URL . self::BALANCE_ENDPOINT,
                'params' => [
                    'uid' => $this->apiKey,
                    'pass' => '[REDACTED]',
                    'format' => 'json'
                ],
            ],
            'transport' => null,
            'response' => null
        ];
        
        $requestResult = $this->makeRequestWithDebug(self::BALANCE_ENDPOINT, $params);
        $response = $requestResult['body'];
        $this->lastRequestDebug['transport'] = $requestResult['transport'];
        $this->lastRequestDebug['response'] = $requestResult['response'];
        
        if ($response === false) {
            $transportError = $this->lastRequestDebug['transport']['curl_error'] ?? '';
            $transportCode = $this->lastRequestDebug['transport']['curl_error_no'] ?? '';
            $httpCode = $this->lastRequestDebug['transport']['http_status'] ?? 0;
            
            $errorMessage = 'Failed to connect to VoodooSMS API';
            if ($transportError !== '') {
                $errorMessage .= ': ' . $transportError;
                if ($transportCode !== '') {
                    $errorMessage .= ' (code ' . $transportCode . ')';
                }
            } elseif ($httpCode > 0) {
                $errorMessage .= ': HTTP ' . $httpCode;
            }
            
            return [
                'success' => false,
                'credits' => 0,
                'error' => $errorMessage
            ];
        }
        
        $parsed = $this->parseResponse($response);
        
        if (!$parsed['success']) {
            $errorCode = $parsed['error_code'] ?? 'UNKNOWN';
            $errorMessage = $parsed['error'] ?? 'Unknown error';
            $httpCode = $this->lastRequestDebug['transport']['http_status'] ?? 0;
            $resultText = strtoupper((string)($parsed['error'] ?? ''));
            $serverIp = $this->lastRequestDebug['transport']['local_ip']
                ?? $this->lastRequestDebug['transport']['remote_ip']
                ?? null;

            if ($httpCode === 401) {
                if (str_contains($resultText, 'UNAUTHORIZED IP')) {
                    $errorMessage = 'IP verification failed (HTTP 401). ' . $errorMessage;
                    if ($serverIp) {
                        $errorMessage .= ' Current server IP: ' . $serverIp . '. Add this IP in VoodooSMS API allowlist.';
                    }
                } else {
                    $errorMessage = 'Invalid credentials (HTTP 401). ' . $errorMessage;
                }
            } elseif ($errorCode === '400') {
                $errorMessage = 'Authentication failed. ' . $errorMessage;
            }
            
            return [
                'success' => false,
                'credits' => 0,
                'error' => $errorMessage,
                'error_code' => $errorCode
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
        return $this->testConnectionWithDetails(false);
    }
    
    /**
     * Test connection and credentials with transport diagnostics
     * 
     * @return array ['success' => bool, 'message' => string, 'credits' => int, 'debug' => array]
     */
    public function testConnectionWithDetails(bool $withDetails = true): array
    {
        $balance = $this->getBalance();
        $debug = [
            'provider' => 'voodoosms',
            'endpoint' => self::API_BASE_URL . self::BALANCE_ENDPOINT,
            'tested_at' => gmdate('c'),
            'php_version' => PHP_VERSION,
            'curl_enabled' => function_exists('curl_version'),
            'sender_id' => $this->senderId
        ];
        
        if ($this->lastRequestDebug) {
            $debug['request'] = $this->lastRequestDebug['request'] ?? [];
            $debug['transport'] = $this->lastRequestDebug['transport'] ?? [];
            $debug['response'] = $this->lastRequestDebug['response'] ?? null;
        }
        
        if ($balance['success']) {
            if ($withDetails) {
                return [
                    'success' => true,
                    'message' => 'Connection successful! Credits available: ' . $balance['credits'],
                    'credits' => $balance['credits'],
                    'debug' => $debug
                ];
            }
            
            return [
                'success' => true,
                'message' => 'Connection successful! Credits available: ' . $balance['credits'],
                'credits' => $balance['credits']
            ];
        }
        
        return [
            'success' => false,
            'message' => 'Connection failed: ' . ($balance['error'] ?? 'Unknown error'),
            'credits' => 0,
            'debug' => $withDetails ? $debug : null
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
        return $this->makeRequestWithDebug($endpoint, $params)['body'];
    }
    
    /**
     * Make HTTP request to VoodooSMS API and return transport metadata
     * 
     * @param string $endpoint API endpoint
     * @param array $params Request parameters
     * @return array {body: string|false, transport: array, response: ?string}
     */
    private function makeRequestWithDebug(string $endpoint, array $params): array
    {
        $url = self::API_BASE_URL . $endpoint;
        
        if (!function_exists('curl_init')) {
            return [
                'body' => false,
                'transport' => [
                    'http_status' => 0,
                    'curl_error' => 'cURL extension is not enabled in PHP',
                    'curl_error_no' => -1,
                    'url' => $url,
                    'endpoint' => $endpoint,
                    'response_status' => 0,
                    'response_length' => 0,
                    'response_preview' => null,
                    'response_headers_preview' => null,
                    'ssl_verify_result' => null,
                    'dns_lookup_time_ms' => null,
                    'connect_time_ms' => null,
                    'total_time_ms' => null,
                    'request_payload' => []
                ],
                'response' => null
            ];
        }
        
        $sanitizedPayload = $params;
        if (isset($sanitizedPayload['pass'])) {
            $sanitizedPayload['pass'] = '[REDACTED]';
        }
        if (isset($sanitizedPayload['uid'])) {
            $sanitizedPayload['uid'] = '[REDACTED]';
        }
        
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_HEADER => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => 'Fundraising-App/1.0',
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
        $headerSize = is_int(curl_getinfo($ch, CURLINFO_HEADER_SIZE)) ? curl_getinfo($ch, CURLINFO_HEADER_SIZE) : 0;
        $rawResponse = $response === false ? '' : (string)$response;
        $headerText = is_string($rawResponse) && $headerSize > 0 ? substr($rawResponse, 0, $headerSize) : null;
        $responseBody = is_string($rawResponse) && $headerSize > 0 ? substr($rawResponse, $headerSize) : $rawResponse;
        if (!is_string($responseBody)) {
            $responseBody = null;
        }
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $info = curl_getinfo($ch);
        $errorNo = curl_errno($ch);
        
        curl_close($ch);
        
        $transport = [
            'http_status' => (int)$httpCode,
            'curl_error' => $error,
            'curl_error_no' => $errorNo,
            'url' => $url,
            'endpoint' => $endpoint,
            'response_status' => $info['http_code'] ?? null,
            'response_length' => is_string($responseBody) ? strlen($responseBody) : 0,
            'response_preview' => is_string($responseBody) ? substr($responseBody, 0, 400) : null,
            'response_headers_preview' => $headerText,
            'ssl_verify_result' => $info['ssl_verify_result'] ?? null,
            'dns_lookup_time_ms' => isset($info['namelookup_time']) ? (int)round($info['namelookup_time'] * 1000) : null,
            'connect_time_ms' => isset($info['connect_time']) ? (int)round($info['connect_time'] * 1000) : null,
            'total_time_ms' => isset($info['total_time']) ? (int)round($info['total_time'] * 1000) : null,
            'request_payload' => $sanitizedPayload,
            'content_type' => $info['content_type'] ?? null,
            'primary_ip' => $info['primary_ip'] ?? null,
            'remote_ip' => $info['primary_ip'] ?? null,
            'local_ip' => $info['local_ip'] ?? null
        ];
        
        if ($error) {
            error_log("VoodooSMS cURL error: $error");
            return [
                'body' => false,
                'transport' => $transport,
                'response' => null
            ];
        }
        
        if ($responseBody === '' || $responseBody === null) {
            error_log("VoodooSMS request failed. HTTP code: $httpCode");
            return [
                'body' => false,
                'transport' => $transport,
                'response' => null
            ];
        }
        
        if ($httpCode >= 500 || $httpCode < 200 || $httpCode >= 300) {
            error_log("VoodooSMS request returned non-success HTTP code: $httpCode");
            return [
                'body' => (string)$responseBody,
                'transport' => $transport,
                'response' => $responseBody
            ];
        }
        
        return [
            'body' => $responseBody,
            'transport' => $transport,
            'response' => $responseBody
        ];
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

