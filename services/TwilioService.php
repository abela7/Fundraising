<?php
/**
 * Twilio Voice Service
 * 
 * Handles all Twilio Voice API operations for click-to-call functionality
 * API Documentation: https://www.twilio.com/docs/voice/api
 * 
 * @author Fundraising System
 */

declare(strict_types=1);

class TwilioService
{
    // API Endpoints
    private const API_VERSION = '2010-04-01';
    private const API_BASE_URL = 'https://api.twilio.com';
    
    private string $accountSid;
    private string $authToken;
    private string $twilioNumber;
    private $db;
    
    /**
     * Constructor
     * 
     * @param string $accountSid Twilio Account SID (ACxxxxxxxxx)
     * @param string $authToken Twilio Auth Token
     * @param string $twilioNumber Your Twilio phone number (+44151XXXXXX)
     * @param mysqli|null $db Database connection for logging
     */
    public function __construct(
        string $accountSid,
        string $authToken,
        string $twilioNumber,
        $db = null
    ) {
        $this->accountSid = $accountSid;
        $this->authToken = $authToken;
        $this->twilioNumber = $this->normalizePhoneNumber($twilioNumber);
        $this->db = $db;
    }
    
    /**
     * Create instance from database settings
     * 
     * @param mysqli $db Database connection
     * @return self|null Returns null if no active settings found
     */
    public static function fromDatabase($db): ?self
    {
        try {
            // Check if table exists
            $check = $db->query("SHOW TABLES LIKE 'twilio_settings'");
            if (!$check || $check->num_rows === 0) {
                return null;
            }
            
            // Get active Twilio settings
            $result = $db->query("
                SELECT account_sid, auth_token, phone_number 
                FROM twilio_settings 
                WHERE is_active = 1 
                ORDER BY id DESC 
                LIMIT 1
            ");
            
            if (!$result || $result->num_rows === 0) {
                return null;
            }
            
            $settings = $result->fetch_assoc();
            
            return new self(
                $settings['account_sid'],
                $settings['auth_token'],
                $settings['phone_number'],
                $db
            );
            
        } catch (Exception $e) {
            error_log("TwilioService::fromDatabase() error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Test Twilio API connection
     * 
     * @return array ['success' => bool, 'message' => string, 'data' => array|null]
     */
    public function testConnection(): array
    {
        try {
            // Fetch account details to verify credentials
            $url = self::API_BASE_URL . '/' . self::API_VERSION . '/Accounts/' . $this->accountSid . '.json';
            
            $response = $this->makeRequest('GET', $url);
            
            if (!$response['success']) {
                return [
                    'success' => false,
                    'message' => $response['error'] ?? 'Failed to connect to Twilio',
                    'data' => null
                ];
            }
            
            $data = $response['data'];
            $status = $data['status'] ?? 'unknown';
            $friendlyName = $data['friendly_name'] ?? 'Unknown';
            
            if ($status === 'active') {
                return [
                    'success' => true,
                    'message' => "Connection successful! Account: {$friendlyName}",
                    'data' => $data
                ];
            } else {
                return [
                    'success' => false,
                    'message' => "Account status is '{$status}'. Please check your Twilio dashboard.",
                    'data' => $data
                ];
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }
    
    /**
     * Initiate a call from agent to donor
     * 
     * This creates a two-leg call:
     * 1. Twilio calls the agent first
     * 2. When agent answers, Twilio calls the donor
     * 3. Both are connected
     * 
     * @param string $agentPhone Agent's phone number
     * @param string $donorPhone Donor's phone number
     * @param string $donorName Donor's name (for greeting)
     * @param int $sessionId Call center session ID
     * @return array ['success' => bool, 'call_sid' => string|null, 'message' => string]
     */
    public function initiateCall(
        string $agentPhone,
        string $donorPhone,
        string $donorName,
        int $sessionId
    ): array {
        try {
            $agentPhone = $this->normalizePhoneNumber($agentPhone);
            $donorPhone = $this->normalizePhoneNumber($donorPhone);
            
            if (!$agentPhone || !$donorPhone) {
                throw new Exception('Invalid phone number format');
            }
            
            // Build TwiML response URL (this will connect agent to donor)
            $baseUrl = 'https://donate.abuneteklehaymanot.org';
            $twimlUrl = $baseUrl . '/admin/call-center/api/twilio-webhook-answer.php';
            $twimlUrl .= '?donor_phone=' . urlencode($donorPhone);
            $twimlUrl .= '&donor_name=' . urlencode($donorName);
            $twimlUrl .= '&session_id=' . $sessionId;
            
            // Status callback URL
            $statusCallbackUrl = $baseUrl . '/admin/call-center/api/twilio-status-callback.php';
            $statusCallbackUrl .= '?session_id=' . $sessionId;
            
            // Recording callback URL (if recording enabled)
            $recordingCallbackUrl = $baseUrl . '/admin/call-center/api/twilio-recording-callback.php';
            $recordingCallbackUrl .= '?session_id=' . $sessionId;
            
            // API endpoint
            $url = self::API_BASE_URL . '/' . self::API_VERSION . '/Accounts/' . $this->accountSid . '/Calls.json';
            
            // Check if recording is enabled
            $recordingEnabled = $this->isRecordingEnabled();
            
            // Call parameters
            $params = [
                'To' => $agentPhone,              // Call agent first
                'From' => $this->twilioNumber,    // Show Twilio number as caller ID
                'Url' => $twimlUrl,                // TwiML instructions
                'Method' => 'GET',
                'StatusCallback' => $statusCallbackUrl,
                'StatusCallbackMethod' => 'POST',
                'StatusCallbackEvent' => ['initiated', 'ringing', 'answered', 'completed'],
                'Record' => $recordingEnabled ? 'true' : 'false',
                'Timeout' => '30',                 // Ring for 30 seconds max
            ];
            
            if ($recordingEnabled) {
                $params['RecordingStatusCallback'] = $recordingCallbackUrl;
                $params['RecordingStatusCallbackMethod'] = 'POST';
            }
            
            // Make API request
            $response = $this->makeRequest('POST', $url, $params);
            
            if (!$response['success']) {
                throw new Exception($response['error'] ?? 'Failed to initiate call');
            }
            
            $callData = $response['data'];
            $callSid = $callData['sid'] ?? null;
            
            if (!$callSid) {
                throw new Exception('No Call SID returned from Twilio');
            }
            
            // Log the call
            $this->logCall($callSid, $sessionId, $agentPhone, $donorPhone, 'outbound-api', $callData);
            
            return [
                'success' => true,
                'call_sid' => $callSid,
                'message' => 'Call initiated. Agent phone will ring first.',
                'data' => $callData
            ];
            
        } catch (Exception $e) {
            error_log("TwilioService::initiateCall() error: " . $e->getMessage());
            return [
                'success' => false,
                'call_sid' => null,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get call status from Twilio
     * 
     * @param string $callSid Twilio Call SID
     * @return array|null Call data or null if not found
     */
    public function getCallStatus(string $callSid): ?array
    {
        try {
            $url = self::API_BASE_URL . '/' . self::API_VERSION . '/Accounts/' . $this->accountSid . '/Calls/' . $callSid . '.json';
            
            $response = $this->makeRequest('GET', $url);
            
            return $response['success'] ? $response['data'] : null;
            
        } catch (Exception $e) {
            error_log("TwilioService::getCallStatus() error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get call recording URL
     * 
     * @param string $callSid Twilio Call SID
     * @return string|null Recording URL or null
     */
    public function getCallRecording(string $callSid): ?string
    {
        try {
            $url = self::API_BASE_URL . '/' . self::API_VERSION . '/Accounts/' . $this->accountSid . '/Recordings.json';
            $url .= '?CallSid=' . urlencode($callSid);
            
            $response = $this->makeRequest('GET', $url);
            
            if (!$response['success'] || empty($response['data']['recordings'])) {
                return null;
            }
            
            $recordings = $response['data']['recordings'];
            if (empty($recordings)) {
                return null;
            }
            
            // Get the first recording
            $recordingSid = $recordings[0]['sid'] ?? null;
            if (!$recordingSid) {
                return null;
            }
            
            // Build recording URL
            return self::API_BASE_URL . '/' . self::API_VERSION . '/Accounts/' . $this->accountSid . '/Recordings/' . $recordingSid . '.mp3';
            
        } catch (Exception $e) {
            error_log("TwilioService::getCallRecording() error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Hangup an active call
     * 
     * @param string $callSid Twilio Call SID
     * @return bool Success
     */
    public function hangupCall(string $callSid): bool
    {
        try {
            $url = self::API_BASE_URL . '/' . self::API_VERSION . '/Accounts/' . $this->accountSid . '/Calls/' . $callSid . '.json';
            
            $response = $this->makeRequest('POST', $url, ['Status' => 'completed']);
            
            return $response['success'];
            
        } catch (Exception $e) {
            error_log("TwilioService::hangupCall() error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Make a notification call with TTS message
     * 
     * @param string $toPhone Phone number to call
     * @param string $twimlUrl URL that returns TwiML with the message
     * @return array ['success' => bool, 'call_sid' => string|null, 'error' => string|null]
     */
    public function makeNotificationCall(string $toPhone, string $twimlUrl): array
    {
        try {
            $toPhone = $this->normalizePhoneNumber($toPhone);
            
            if (!$toPhone) {
                return [
                    'success' => false,
                    'call_sid' => null,
                    'error' => 'Invalid phone number format'
                ];
            }
            
            // API endpoint
            $url = self::API_BASE_URL . '/' . self::API_VERSION . '/Accounts/' . $this->accountSid . '/Calls.json';
            
            // Call parameters
            $params = [
                'To' => $toPhone,
                'From' => $this->twilioNumber,
                'Url' => $twimlUrl,
                'Method' => 'GET',
                'Timeout' => '30',
            ];
            
            // Make API request
            $response = $this->makeRequest('POST', $url, $params);
            
            if (!$response['success']) {
                return [
                    'success' => false,
                    'call_sid' => null,
                    'error' => $response['error'] ?? 'Failed to initiate call'
                ];
            }
            
            $callData = $response['data'];
            $callSid = $callData['sid'] ?? null;
            
            if (!$callSid) {
                return [
                    'success' => false,
                    'call_sid' => null,
                    'error' => 'No Call SID returned from Twilio'
                ];
            }
            
            error_log("Notification call initiated: {$callSid} to {$toPhone}");
            
            return [
                'success' => true,
                'call_sid' => $callSid,
                'error' => null
            ];
            
        } catch (Exception $e) {
            error_log("TwilioService::makeNotificationCall() error: " . $e->getMessage());
            return [
                'success' => false,
                'call_sid' => null,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Normalize phone number to E.164 format
     * 
     * @param string $phone Phone number
     * @return string|null Normalized number or null if invalid
     */
    private function normalizePhoneNumber(string $phone): ?string
    {
        // Remove all non-digit characters except +
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        
        // Already in E.164 format
        if (strpos($phone, '+') === 0) {
            return $phone;
        }
        
        // UK mobile (07xxx) -> +447xxx
        if (preg_match('/^07[0-9]{9}$/', $phone)) {
            return '+44' . substr($phone, 1);
        }
        
        // UK landline (0151xxx) -> +44151xxx
        if (preg_match('/^0[0-9]{10}$/', $phone)) {
            return '+44' . substr($phone, 1);
        }
        
        // Already starts with 44 but no +
        if (preg_match('/^44[0-9]{10,11}$/', $phone)) {
            return '+' . $phone;
        }
        
        return null;
    }
    
    /**
     * Make HTTP request to Twilio API
     * 
     * @param string $method HTTP method (GET, POST)
     * @param string $url Full API URL
     * @param array $params Request parameters
     * @return array ['success' => bool, 'data' => array|null, 'error' => string|null]
     */
    private function makeRequest(string $method, string $url, array $params = []): array
    {
        $ch = curl_init();
        
        // Set method and data
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        } elseif ($method === 'GET' && !empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERPWD => $this->accountSid . ':' . $this->authToken,  // Basic Auth
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
            return [
                'success' => false,
                'data' => null,
                'error' => 'cURL Error: ' . $error
            ];
        }
        
        $data = json_decode($response, true);
        
        if ($httpCode >= 200 && $httpCode < 300) {
            return [
                'success' => true,
                'data' => $data,
                'error' => null
            ];
        } else {
            $errorMessage = $data['message'] ?? 'Unknown error';
            $errorCode = $data['code'] ?? $httpCode;
            
            return [
                'success' => false,
                'data' => $data,
                'error' => "Twilio Error {$errorCode}: {$errorMessage}"
            ];
        }
    }
    
    /**
     * Log call to database
     * 
     * @param string $callSid Twilio Call SID
     * @param int $sessionId Call center session ID
     * @param string $fromNumber From phone number
     * @param string $toNumber To phone number
     * @param string $direction Call direction
     * @param array $callData Full call data from Twilio
     */
    private function logCall(
        string $callSid,
        int $sessionId,
        string $fromNumber,
        string $toNumber,
        string $direction,
        array $callData
    ): void {
        if (!$this->db) {
            return;
        }
        
        try {
            // Check if table exists
            $check = $this->db->query("SHOW TABLES LIKE 'twilio_call_logs'");
            if (!$check || $check->num_rows === 0) {
                return;
            }
            
            $status = $callData['status'] ?? 'queued';
            $price = isset($callData['price']) ? (float)$callData['price'] : null;
            $priceUnit = $callData['price_unit'] ?? 'GBP';
            $webhookData = json_encode($callData);
            
            $stmt = $this->db->prepare("
                INSERT INTO twilio_call_logs 
                (call_sid, session_id, from_number, to_number, direction, status, price, price_unit, webhook_data)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    status = VALUES(status),
                    price = VALUES(price),
                    webhook_data = VALUES(webhook_data),
                    updated_at = NOW()
            ");
            
            $stmt->bind_param('sissssdss',
                $callSid, $sessionId, $fromNumber, $toNumber, $direction,
                $status, $price, $priceUnit, $webhookData
            );
            
            $stmt->execute();
            $stmt->close();
            
        } catch (Exception $e) {
            error_log("TwilioService::logCall() error: " . $e->getMessage());
        }
    }
    
    /**
     * Check if recording is enabled in settings
     * 
     * @return bool
     */
    private function isRecordingEnabled(): bool
    {
        if (!$this->db) {
            return true; // Default to enabled
        }
        
        try {
            $result = $this->db->query("
                SELECT recording_enabled 
                FROM twilio_settings 
                WHERE is_active = 1 
                ORDER BY id DESC 
                LIMIT 1
            ");
            
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                return (bool)$row['recording_enabled'];
            }
            
        } catch (Exception $e) {
            error_log("TwilioService::isRecordingEnabled() error: " . $e->getMessage());
        }
        
        return true;
    }
    
    /**
     * Update call center session with Twilio data
     * 
     * @param int $sessionId Session ID
     * @param string $callSid Twilio Call SID
     * @param array $data Additional data to update
     */
    public function updateSession(int $sessionId, string $callSid, array $data = []): void
    {
        if (!$this->db) {
            return;
        }
        
        try {
            $status = $data['status'] ?? null;
            $duration = isset($data['duration']) ? (int)$data['duration'] : null;
            $recordingUrl = $data['recording_url'] ?? null;
            
            $stmt = $this->db->prepare("
                UPDATE call_center_sessions 
                SET call_source = 'twilio',
                    twilio_call_sid = ?,
                    twilio_status = COALESCE(?, twilio_status),
                    twilio_duration = COALESCE(?, twilio_duration),
                    twilio_recording_url = COALESCE(?, twilio_recording_url),
                    duration_seconds = COALESCE(?, duration_seconds),
                    updated_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->bind_param('ssissi',
                $callSid, $status, $duration, $recordingUrl, $duration, $sessionId
            );
            
            $stmt->execute();
            $stmt->close();
            
        } catch (Exception $e) {
            error_log("TwilioService::updateSession() error: " . $e->getMessage());
        }
    }
}

