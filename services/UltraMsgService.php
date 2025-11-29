<?php
/**
 * UltraMsg WhatsApp Service
 * 
 * Handles all WhatsApp operations via UltraMsg REST API
 * API Documentation: https://docs.ultramsg.com/
 * 
 * @author Fundraising System
 */

declare(strict_types=1);

class UltraMsgService
{
    // API Base URL (instance ID gets inserted)
    private const API_BASE_URL = 'https://api.ultramsg.com/';
    
    private string $instanceId;
    private string $token;
    private ?int $providerId;
    private $db;
    
    /**
     * Constructor
     * 
     * @param string $instanceId UltraMsg Instance ID
     * @param string $token UltraMsg API Token
     * @param mysqli|null $db Database connection for logging
     * @param int|null $providerId Provider ID in database
     */
    public function __construct(
        string $instanceId, 
        string $token, 
        $db = null,
        ?int $providerId = null
    ) {
        $this->instanceId = $instanceId;
        $this->token = $token;
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
            $check = $db->query("SHOW TABLES LIKE 'whatsapp_providers'");
            if (!$check || $check->num_rows === 0) {
                return null;
            }
            
            // Get active UltraMsg provider
            $result = $db->query("
                SELECT id, instance_id, api_token 
                FROM whatsapp_providers 
                WHERE provider_name = 'ultramsg' AND is_active = 1 
                ORDER BY is_default DESC 
                LIMIT 1
            ");
            
            if (!$result || $result->num_rows === 0) {
                return null;
            }
            
            $provider = $result->fetch_assoc();
            
            return new self(
                $provider['instance_id'],
                $provider['api_token'],
                $db,
                (int)$provider['id']
            );
        } catch (Exception $e) {
            error_log("UltraMsg: Failed to load from database - " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Send WhatsApp text message
     * 
     * @param string $phoneNumber Recipient phone number (UK format)
     * @param string $message Message content
     * @param array $options Additional options (donor_id, template_id, source_type, etc.)
     * @return array Result with 'success', 'message_id', 'error'
     */
    public function send(string $phoneNumber, string $message, array $options = []): array
    {
        $startTime = microtime(true);
        
        // Normalize phone number to international format with +
        $phoneNumber = $this->normalizePhoneNumber($phoneNumber);
        
        if (!$phoneNumber) {
            return [
                'success' => false,
                'error' => 'Invalid phone number format',
                'error_code' => 'INVALID_PHONE'
            ];
        }
        
        // Build API request
        $params = [
            'token' => $this->token,
            'to' => $phoneNumber,
            'body' => $message
        ];
        
        // Add priority if specified
        if (!empty($options['priority'])) {
            $params['priority'] = $options['priority'];
        }
        
        // Make API request
        $response = $this->makeRequest('messages/chat', $params);
        $duration = round((microtime(true) - $startTime) * 1000);
        
        // Parse response
        $result = $this->parseResponse($response);
        
        // Log to database if connection available
        if ($this->db && !empty($options['log'])) {
            $this->logWhatsApp(
                $phoneNumber,
                $message,
                $result['success'] ? 'sent' : 'failed',
                $result['message_id'] ?? null,
                $result['error'] ?? null,
                $options
            );
        }
        
        // Update provider stats
        if ($this->db && $this->providerId) {
            $this->updateProviderStats($result['success']);
        }
        
        return array_merge($result, [
            'duration_ms' => $duration,
            'phone_number' => $phoneNumber,
            'channel' => 'whatsapp'
        ]);
    }
    
    /**
     * Send WhatsApp image message
     * 
     * @param string $phoneNumber Recipient phone number
     * @param string $imageUrl URL to the image
     * @param string $caption Optional caption
     * @param array $options Additional options
     * @return array Result
     */
    public function sendImage(string $phoneNumber, string $imageUrl, string $caption = '', array $options = []): array
    {
        $phoneNumber = $this->normalizePhoneNumber($phoneNumber);
        
        if (!$phoneNumber) {
            return [
                'success' => false,
                'error' => 'Invalid phone number format',
                'error_code' => 'INVALID_PHONE'
            ];
        }
        
        $params = [
            'token' => $this->token,
            'to' => $phoneNumber,
            'image' => $imageUrl,
            'caption' => $caption
        ];
        
        $response = $this->makeRequest('messages/image', $params);
        return $this->parseResponse($response);
    }
    
    /**
     * Send WhatsApp document
     * 
     * @param string $phoneNumber Recipient phone number
     * @param string $documentUrl URL to the document
     * @param string $filename Filename to display
     * @param string $caption Optional caption
     * @param array $options Additional options
     * @return array Result
     */
    public function sendDocument(string $phoneNumber, string $documentUrl, string $filename = '', string $caption = '', array $options = []): array
    {
        $phoneNumber = $this->normalizePhoneNumber($phoneNumber);
        
        if (!$phoneNumber) {
            return [
                'success' => false,
                'error' => 'Invalid phone number format',
                'error_code' => 'INVALID_PHONE'
            ];
        }
        
        $params = [
            'token' => $this->token,
            'to' => $phoneNumber,
            'document' => $documentUrl,
            'filename' => $filename,
            'caption' => $caption
        ];
        
        $response = $this->makeRequest('messages/document', $params);
        return $this->parseResponse($response);
    }
    
    /**
     * Send WhatsApp audio/voice message
     * 
     * @param string $phoneNumber Recipient phone number
     * @param string $audioUrl URL to the audio file
     * @param array $options Additional options
     * @return array Result
     */
    public function sendAudio(string $phoneNumber, string $audioUrl, array $options = []): array
    {
        $phoneNumber = $this->normalizePhoneNumber($phoneNumber);
        
        if (!$phoneNumber) {
            return [
                'success' => false,
                'error' => 'Invalid phone number format',
                'error_code' => 'INVALID_PHONE'
            ];
        }
        
        $params = [
            'token' => $this->token,
            'to' => $phoneNumber,
            'audio' => $audioUrl
        ];
        
        $response = $this->makeRequest('messages/audio', $params);
        return $this->parseResponse($response);
    }
    
    /**
     * Send WhatsApp video message
     * 
     * @param string $phoneNumber Recipient phone number
     * @param string $videoUrl URL to the video file
     * @param string $caption Optional caption
     * @param array $options Additional options
     * @return array Result
     */
    public function sendVideo(string $phoneNumber, string $videoUrl, string $caption = '', array $options = []): array
    {
        $phoneNumber = $this->normalizePhoneNumber($phoneNumber);
        
        if (!$phoneNumber) {
            return [
                'success' => false,
                'error' => 'Invalid phone number format',
                'error_code' => 'INVALID_PHONE'
            ];
        }
        
        $params = [
            'token' => $this->token,
            'to' => $phoneNumber,
            'video' => $videoUrl,
            'caption' => $caption
        ];
        
        $response = $this->makeRequest('messages/video', $params);
        return $this->parseResponse($response);
    }
    
    /**
     * Send WhatsApp voice note (PTT - Push to Talk)
     * 
     * @param string $phoneNumber Recipient phone number
     * @param string $audioUrl URL to the audio file (will be sent as voice note)
     * @param array $options Additional options
     * @return array Result
     */
    public function sendVoice(string $phoneNumber, string $audioUrl, array $options = []): array
    {
        $phoneNumber = $this->normalizePhoneNumber($phoneNumber);
        
        if (!$phoneNumber) {
            return [
                'success' => false,
                'error' => 'Invalid phone number format',
                'error_code' => 'INVALID_PHONE'
            ];
        }
        
        $params = [
            'token' => $this->token,
            'to' => $phoneNumber,
            'audio' => $audioUrl
        ];
        
        // Use voice endpoint for PTT messages
        $response = $this->makeRequest('messages/voice', $params);
        return $this->parseResponse($response);
    }
    
    /**
     * Send any type of media message
     * 
     * @param string $phoneNumber Recipient phone number
     * @param string $mediaUrl URL to the media file
     * @param string $type Media type: image, document, audio, video, voice
     * @param string $caption Optional caption
     * @param string $filename Optional filename (for documents)
     * @return array Result
     */
    public function sendMedia(string $phoneNumber, string $mediaUrl, string $type = 'image', string $caption = '', string $filename = ''): array
    {
        switch ($type) {
            case 'image':
                return $this->sendImage($phoneNumber, $mediaUrl, $caption);
            case 'video':
                return $this->sendVideo($phoneNumber, $mediaUrl, $caption);
            case 'audio':
                return $this->sendAudio($phoneNumber, $mediaUrl);
            case 'voice':
                return $this->sendVoice($phoneNumber, $mediaUrl);
            case 'document':
            default:
                return $this->sendDocument($phoneNumber, $mediaUrl, $filename, $caption);
        }
    }
    
    /**
     * Get instance status
     * 
     * @return array ['success' => bool, 'status' => string, 'error' => string]
     */
    public function getStatus(): array
    {
        $params = [
            'token' => $this->token
        ];
        
        $response = $this->makeRequest('instance/status', $params, 'GET');
        
        if ($response === false) {
            return [
                'success' => false,
                'status' => 'unknown',
                'error' => 'Failed to connect to UltraMsg API'
            ];
        }
        
        $data = json_decode($response, true);
        
        if (isset($data['status'])) {
            return [
                'success' => true,
                'status' => $data['status']['accountStatus']['status'] ?? $data['status'] ?? 'unknown',
                'phone' => $data['status']['accountStatus']['substatus'] ?? null,
                'error' => null
            ];
        }
        
        return [
            'success' => false,
            'status' => 'error',
            'error' => $data['error'] ?? 'Unknown error'
        ];
    }
    
    /**
     * Get QR code for authentication (if needed)
     * 
     * @return array ['success' => bool, 'qr' => string, 'error' => string]
     */
    public function getQR(): array
    {
        $params = [
            'token' => $this->token
        ];
        
        $response = $this->makeRequest('instance/qr', $params, 'GET');
        
        if ($response === false) {
            return [
                'success' => false,
                'qr' => null,
                'error' => 'Failed to connect to UltraMsg API'
            ];
        }
        
        $data = json_decode($response, true);
        
        if (isset($data['qrCode'])) {
            return [
                'success' => true,
                'qr' => $data['qrCode'],
                'error' => null
            ];
        }
        
        return [
            'success' => false,
            'qr' => null,
            'error' => $data['error'] ?? 'No QR code available'
        ];
    }
    
    /**
     * Test connection and credentials
     * 
     * @return array ['success' => bool, 'message' => string]
     */
    public function testConnection(): array
    {
        $status = $this->getStatus();
        
        if ($status['success']) {
            $statusText = $status['status'];
            if ($statusText === 'authenticated') {
                return [
                    'success' => true,
                    'message' => 'Connection successful! WhatsApp is authenticated and ready.',
                    'status' => $statusText
                ];
            } else {
                return [
                    'success' => true,
                    'message' => "Connected but status is: {$statusText}. You may need to scan QR code.",
                    'status' => $statusText
                ];
            }
        }
        
        return [
            'success' => false,
            'message' => 'Connection failed: ' . ($status['error'] ?? 'Unknown error'),
            'status' => 'error'
        ];
    }
    
    /**
     * Check if a phone number has WhatsApp
     * 
     * @param string $phoneNumber Phone number to check
     * @return array ['success' => bool, 'has_whatsapp' => bool]
     */
    public function checkNumber(string $phoneNumber): array
    {
        $phoneNumber = $this->normalizePhoneNumber($phoneNumber);
        
        if (!$phoneNumber) {
            return [
                'success' => false,
                'has_whatsapp' => false,
                'error' => 'Invalid phone number'
            ];
        }
        
        $params = [
            'token' => $this->token,
            'chatId' => $phoneNumber
        ];
        
        $response = $this->makeRequest('contacts/check', $params, 'GET');
        $data = json_decode($response, true);
        
        if (isset($data['status'])) {
            return [
                'success' => true,
                'has_whatsapp' => $data['status'] === 'valid',
                'error' => null
            ];
        }
        
        return [
            'success' => false,
            'has_whatsapp' => false,
            'error' => $data['error'] ?? 'Unknown error'
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
            return '+44' . $matches[1];
        }
        
        if (preg_match('/^44(\d{10})$/', $phone)) {
            // International without +
            return '+' . $phone;
        }
        
        if (preg_match('/^0([1-9]\d{9})$/', $phone, $matches)) {
            // UK national format 07xxx or 01xxx
            return '+44' . $matches[1];
        }
        
        if (preg_match('/^([1-9]\d{9})$/', $phone)) {
            // 10 digit without leading 0
            return '+44' . $phone;
        }
        
        // Invalid format
        return null;
    }
    
    /**
     * Make HTTP request to UltraMsg API
     * 
     * @param string $endpoint API endpoint
     * @param array $params Request parameters
     * @param string $method HTTP method (GET or POST)
     * @return string|false Response body or false on failure
     */
    private function makeRequest(string $endpoint, array $params, string $method = 'POST')
    {
        $url = self::API_BASE_URL . $this->instanceId . '/' . $endpoint;
        
        $ch = curl_init();
        
        if ($method === 'GET') {
            $url .= '?' . http_build_query($params);
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        } else {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        }
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded'
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($error) {
            error_log("UltraMsg cURL error: $error");
            return false;
        }
        
        if ($httpCode >= 400) {
            error_log("UltraMsg HTTP error: $httpCode - $response");
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
                'error' => 'Failed to connect to UltraMsg API',
                'error_code' => 'CONNECTION_FAILED',
                'message_id' => null
            ];
        }
        
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'error' => 'Invalid API response: ' . substr($response, 0, 100),
                'error_code' => 'INVALID_RESPONSE',
                'message_id' => null
            ];
        }
        
        // Log raw response for debugging
        error_log("UltraMsg API Response: " . json_encode($data));
        
        // Check for sent status (can be string 'true' or boolean true)
        $isSent = false;
        if (isset($data['sent'])) {
            $isSent = ($data['sent'] === 'true' || $data['sent'] === true);
        }
        
        // Get message ID - convert to string if integer
        $messageId = null;
        if (isset($data['id'])) {
            $messageId = (string)$data['id'];
        }
        
        // Success conditions
        if ($isSent || ($messageId && !isset($data['error']))) {
            return [
                'success' => true,
                'message_id' => $messageId,
                'error' => null,
                'raw_response' => $data
            ];
        }
        
        // Error response - ensure error is always a string
        $errorMsg = 'Unknown error';
        if (isset($data['error'])) {
            $errorMsg = is_array($data['error']) ? json_encode($data['error']) : (string)$data['error'];
        } elseif (isset($data['message'])) {
            $errorMsg = is_array($data['message']) ? json_encode($data['message']) : (string)$data['message'];
        }
        
        return [
            'success' => false,
            'error' => $errorMsg,
            'error_code' => $data['error_code'] ?? 'UNKNOWN',
            'message_id' => $messageId,
            'raw_response' => $data
        ];
    }
    
    /**
     * Log WhatsApp message to database
     * 
     * @param string $phoneNumber Recipient phone number
     * @param string $message Message content
     * @param string $status Message status
     * @param mixed $messageId Message ID from API (can be int or string)
     * @param string|null $error Error message if any
     * @param array $options Additional options
     */
    private function logWhatsApp(
        string $phoneNumber,
        string $message,
        string $status,
        $messageId,
        ?string $error,
        array $options
    ): void {
        // Convert message ID to string
        $messageId = $messageId !== null ? (string)$messageId : null;
        try {
            // Check if whatsapp_log table exists
            $check = $this->db->query("SHOW TABLES LIKE 'whatsapp_log'");
            if (!$check || $check->num_rows === 0) {
                // Fall back to sms_log with channel indicator
                $check2 = $this->db->query("SHOW TABLES LIKE 'sms_log'");
                if (!$check2 || $check2->num_rows === 0) {
                    return;
                }
                
                // Log to sms_log with source_type = 'whatsapp'
                $donorId = $options['donor_id'] ?? null;
                $templateId = $options['template_id'] ?? null;
                $sourceType = 'whatsapp';
                $language = $options['language'] ?? 'en';
                
                $stmt = $this->db->prepare("
                    INSERT INTO sms_log 
                    (donor_id, phone_number, template_id, message_content, message_language,
                     provider_id, provider_message_id, status, error_message, segments,
                     cost_pence, source_type, sent_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0, ?, NOW())
                ");
                
                if ($stmt) {
                    $stmt->bind_param(
                        'isissiisss',
                        $donorId,
                        $phoneNumber,
                        $templateId,
                        $message,
                        $language,
                        $this->providerId,
                        $messageId,
                        $status,
                        $error,
                        $sourceType
                    );
                    $stmt->execute();
                }
                return;
            }
            
            // Log to dedicated whatsapp_log table
            $donorId = $options['donor_id'] ?? null;
            $templateId = $options['template_id'] ?? null;
            $sourceType = $options['source_type'] ?? 'api';
            $language = $options['language'] ?? 'en';
            
            $stmt = $this->db->prepare("
                INSERT INTO whatsapp_log 
                (donor_id, phone_number, template_id, message_content, message_language,
                 provider_id, provider_message_id, status, error_message, source_type, sent_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            if ($stmt) {
                $stmt->bind_param(
                    'isissiisss',
                    $donorId,
                    $phoneNumber,
                    $templateId,
                    $message,
                    $language,
                    $this->providerId,
                    $messageId,
                    $status,
                    $error,
                    $sourceType
                );
                $stmt->execute();
            }
        } catch (Exception $e) {
            error_log("UltraMsg: Failed to log message - " . $e->getMessage());
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
                    UPDATE whatsapp_providers 
                    SET last_success_at = NOW(), 
                        failure_count = 0,
                        messages_sent = messages_sent + 1,
                        updated_at = NOW()
                    WHERE id = {$this->providerId}
                ");
            } else {
                $this->db->query("
                    UPDATE whatsapp_providers 
                    SET failure_count = failure_count + 1,
                        updated_at = NOW()
                    WHERE id = {$this->providerId}
                ");
            }
        } catch (Exception $e) {
            error_log("UltraMsg: Failed to update provider stats - " . $e->getMessage());
        }
    }
    
    /**
     * Get message statistics
     * 
     * @return array ['success' => bool, 'stats' => array]
     */
    public function getStatistics(): array
    {
        $params = [
            'token' => $this->token
        ];
        
        $response = $this->makeRequest('messages/statistics', $params, 'GET');
        
        if ($response === false) {
            return [
                'success' => false,
                'stats' => null,
                'error' => 'Failed to connect to UltraMsg API'
            ];
        }
        
        $data = json_decode($response, true);
        
        if (isset($data['statistics']) || isset($data['sent']) || isset($data['received'])) {
            return [
                'success' => true,
                'stats' => $data,
                'error' => null
            ];
        }
        
        return [
            'success' => false,
            'stats' => $data,
            'error' => $data['error'] ?? 'Unknown response format'
        ];
    }
    
    /**
     * Get sent messages with delivery status
     * 
     * @param int $page Page number (for pagination)
     * @param int $limit Messages per page
     * @return array ['success' => bool, 'messages' => array]
     */
    public function getMessages(int $page = 1, int $limit = 100): array
    {
        $params = [
            'token' => $this->token,
            'page' => $page,
            'limit' => $limit
        ];
        
        $response = $this->makeRequest('messages', $params, 'GET');
        
        if ($response === false) {
            return [
                'success' => false,
                'messages' => [],
                'error' => 'Failed to connect to UltraMsg API'
            ];
        }
        
        $data = json_decode($response, true);
        
        if (isset($data['messages']) || is_array($data)) {
            return [
                'success' => true,
                'messages' => $data['messages'] ?? $data,
                'error' => null
            ];
        }
        
        return [
            'success' => false,
            'messages' => [],
            'error' => $data['error'] ?? 'Unknown response format'
        ];
    }
    
    /**
     * Get instance info including connected phone number
     * 
     * @return array ['success' => bool, 'info' => array]
     */
    public function getInstanceInfo(): array
    {
        $params = [
            'token' => $this->token
        ];
        
        $response = $this->makeRequest('instance/me', $params, 'GET');
        
        if ($response === false) {
            return [
                'success' => false,
                'info' => null,
                'error' => 'Failed to connect to UltraMsg API'
            ];
        }
        
        $data = json_decode($response, true);
        
        if (isset($data['id']) || isset($data['me'])) {
            return [
                'success' => true,
                'info' => $data,
                'phone' => $data['me']['id'] ?? $data['id'] ?? null,
                'name' => $data['me']['name'] ?? $data['name'] ?? null,
                'error' => null
            ];
        }
        
        return [
            'success' => false,
            'info' => $data,
            'error' => $data['error'] ?? 'Unknown response format'
        ];
    }
    
    /**
     * Get account settings
     * 
     * @return array Account settings
     */
    public function getSettings(): array
    {
        $params = [
            'token' => $this->token
        ];
        
        $response = $this->makeRequest('instance/settings', $params, 'GET');
        
        if ($response === false) {
            return [
                'success' => false,
                'settings' => null,
                'error' => 'Failed to connect'
            ];
        }
        
        $data = json_decode($response, true);
        
        return [
            'success' => !isset($data['error']),
            'settings' => $data,
            'error' => $data['error'] ?? null
        ];
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

