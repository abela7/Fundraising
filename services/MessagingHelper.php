<?php
/**
 * Unified Messaging Helper - SMS & WhatsApp
 * 
 * A top-tier unified messaging system that supports both SMS and WhatsApp
 * with intelligent channel selection, fallback, and template management.
 * 
 * Usage:
 *   $msg = new MessagingHelper($db);
 *   $msg->send('payment_reminder_3day', $donorId, ['name' => 'John', 'amount' => '£50']);
 *   $msg->sendDirect($phoneNumber, $message, 'whatsapp'); // or 'sms', 'auto'
 *   $msg->sendToDonor($donorId, $message, 'auto'); // Auto-select best channel
 * 
 * @author Fundraising System
 * @version 1.0.0
 */

declare(strict_types=1);

require_once __DIR__ . '/SMSHelper.php';
require_once __DIR__ . '/UltraMsgService.php';
require_once __DIR__ . '/VoodooSMSService.php';

// Load auth functions if available (for getting current user)
if (file_exists(__DIR__ . '/../shared/auth.php')) {
    require_once __DIR__ . '/../shared/auth.php';
}

class MessagingHelper
{
    private $db;
    private ?SMSHelper $smsHelper = null;
    private ?UltraMsgService $whatsappService = null;
    private array $errors = [];
    private bool $initialized = false;
    private ?int $currentUserId = null;
    private ?array $currentUser = null;
    private bool $whatsappChecked = false;
    private bool $whatsappReady = false;
    
    // Channel preference constants
    public const CHANNEL_AUTO = 'auto';
    public const CHANNEL_SMS = 'sms';
    public const CHANNEL_WHATSAPP = 'whatsapp';
    public const CHANNEL_BOTH = 'both';
    
    /**
     * Constructor
     * 
     * @param mysqli $db Database connection
     */
    public function __construct($db)
    {
        $this->db = $db;
        $this->initialize();
    }
    
    /**
     * Initialize services
     */
    private function initialize(): void
    {
        if ($this->initialized) return;
        
        try {
            // Initialize SMS helper
            $this->smsHelper = new SMSHelper($this->db);
            
            // Initialize WhatsApp service
            $this->whatsappService = UltraMsgService::fromDatabase($this->db);
            
            // Get current user for logging
            $this->loadCurrentUser();
            
            $this->initialized = true;
        } catch (Exception $e) {
            $this->errors[] = 'Initialization failed: ' . $e->getMessage();
            error_log("MessagingHelper init error: " . $e->getMessage());
        }
    }
    
    /**
     * Load current user from session (for tracking who sent messages)
     */
    private function loadCurrentUser(): void
    {
        try {
            if (function_exists('current_user')) {
                $this->currentUser = current_user();
                $this->currentUserId = $this->currentUser['id'] ?? null;
            }
        } catch (Exception $e) {
            // User not logged in or session not available - that's OK for cron/system sends
            $this->currentUserId = null;
            $this->currentUser = null;
        }
    }
    
    /**
     * Set current user manually (useful for cron jobs or API calls)
     * 
     * @param int|null $userId User ID
     * @param array|null $userData User data array
     */
    public function setCurrentUser(?int $userId, ?array $userData = null): void
    {
        $this->currentUserId = $userId;
        $this->currentUser = $userData;
    }
    
    /**
     * Check if SMS is available
     */
    public function isSMSAvailable(): bool
    {
        return $this->smsHelper !== null && $this->smsHelper->isReady();
    }
    
    /**
     * Check if WhatsApp is available
     */
    public function isWhatsAppAvailable(): bool
    {
        if (!$this->whatsappService) {
            return false;
        }

        // If we already verified this session, don't hit the API again
        if ($this->whatsappChecked) {
            return $this->whatsappReady;
        }

        $this->whatsappChecked = true;

        try {
            $status = $this->whatsappService->getStatus();
            $statusValue = strtolower($status['status'] ?? 'unknown');

            // Accept any status that means the instance is usable
            // UltraMsg statuses: authenticated, connected, ready, standby, loading
            // Only reject clearly bad states
            $badStatuses = ['disconnected', 'initialize', 'qr', 'error', 'unknown'];
            $this->whatsappReady = $status['success'] && !in_array($statusValue, $badStatuses);

            if (!$this->whatsappReady) {
                error_log("MessagingHelper: WhatsApp not available, status=$statusValue");
            }
        } catch (\Throwable $e) {
            error_log("MessagingHelper: WhatsApp status check failed: " . $e->getMessage());
            // If status check itself fails, still try to send
            // The actual send will fail with a clear error if the service is truly down
            $this->whatsappReady = true;
        }

        return $this->whatsappReady;
    }
    
    /**
     * Get initialization errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
    
    /**
     * Send message using template (auto-selects best channel)
     * 
     * @param string $templateKey Template key
     * @param int $donorId Donor ID
     * @param array $variables Template variables
     * @param string $preferredChannel 'auto', 'sms', 'whatsapp', 'both'
     * @param string $sourceType Source type
     * @param bool $queue Queue for later if true
     * @param bool $forceImmediate Force immediate send
     * @return array Result with 'success', 'channel', 'message_id', etc.
     */
    public function sendFromTemplate(
        string $templateKey,
        int $donorId,
        array $variables = [],
        string $preferredChannel = self::CHANNEL_AUTO,
        string $sourceType = 'system',
        bool $queue = false,
        bool $forceImmediate = false
    ): array {
        // Get donor info
        $donor = $this->getDonor($donorId);
        if (!$donor) {
            return $this->error("Donor #$donorId not found");
        }
        
        // Determine best channel
        $channel = $this->determineChannel($donor, $preferredChannel);
        
        if ($channel === self::CHANNEL_BOTH) {
            // Send via both channels
            return $this->sendViaBothChannels($templateKey, $donorId, $variables, $sourceType, $queue, $forceImmediate);
        }
        
        // Send via single channel
        if ($channel === self::CHANNEL_WHATSAPP) {
            return $this->sendWhatsAppFromTemplate($templateKey, $donorId, $variables, $sourceType, $queue, $forceImmediate);
        } else {
            // Send via SMS - SMSHelper logs via VoodooSMSService
            $smsResult = $this->smsHelper->sendFromTemplate($templateKey, $donorId, $variables, $sourceType, $queue, $forceImmediate);
            return $smsResult;
        }
    }
    
    /**
     * Send direct message (no template)
     * 
     * @param string $phoneNumber Phone number
     * @param string $message Message content
     * @param string $channel 'auto', 'sms', 'whatsapp', 'both'
     * @param int|null $donorId Optional donor ID
     * @param string $sourceType Source type
     * @return array Result
     */
    public function sendDirect(
        string $phoneNumber,
        string $message,
        string $channel = self::CHANNEL_AUTO,
        ?int $donorId = null,
        string $sourceType = 'manual'
    ): array {
        // Get donor if ID provided
        $donor = $donorId ? $this->getDonor($donorId) : null;
        
        // Determine channel
        if ($channel === self::CHANNEL_AUTO && $donor) {
            $channel = $this->determineChannel($donor, self::CHANNEL_AUTO);
        } elseif ($channel === self::CHANNEL_AUTO) {
            // No donor info - prefer WhatsApp if available, else SMS
            $channel = $this->isWhatsAppAvailable() ? self::CHANNEL_WHATSAPP : self::CHANNEL_SMS;
        }
        
        // Normalize phone number
        $phoneNumber = $this->normalizePhoneNumber($phoneNumber);
        if (!$phoneNumber) {
            return $this->error('Invalid phone number format');
        }
        
        // Send via selected channel(s)
        if ($channel === self::CHANNEL_BOTH) {
            return $this->sendDirectViaBoth($phoneNumber, $message, $donorId, $sourceType);
        } elseif ($channel === self::CHANNEL_WHATSAPP) {
            return $this->sendWhatsAppDirect($phoneNumber, $message, $donorId, $sourceType);
        } else {
            // Send via SMS - SMSHelper logs via VoodooSMSService
            $smsResult = $this->smsHelper->sendDirect($phoneNumber, $message, $donorId, $sourceType);
            return $smsResult;
        }
    }
    
    /**
     * Send message to donor (auto-detects best channel)
     * 
     * @param int $donorId Donor ID
     * @param string $message Message content
     * @param string $preferredChannel Preferred channel
     * @param string $sourceType Source type
     * @return array Result
     */
    public function sendToDonor(
        int $donorId,
        string $message,
        string $preferredChannel = self::CHANNEL_AUTO,
        string $sourceType = 'manual'
    ): array {
        $donor = $this->getDonor($donorId);
        if (!$donor) {
            return $this->error("Donor #$donorId not found");
        }
        
        if (empty($donor['phone'])) {
            return $this->error("Donor #$donorId has no phone number");
        }
        
        return $this->sendDirect($donor['phone'], $message, $preferredChannel, $donorId, $sourceType);
    }
    
    /**
     * Determine best channel for donor
     * 
     * @param array $donor Donor data
     * @param string $preferredChannel Preferred channel
     * @return string Selected channel
     */
    private function determineChannel(array $donor, string $preferredChannel): string
    {
        // If explicit channel requested, use it (if available)
        if ($preferredChannel === self::CHANNEL_SMS) {
            return $this->isSMSAvailable() ? self::CHANNEL_SMS : self::CHANNEL_WHATSAPP;
        }
        
        if ($preferredChannel === self::CHANNEL_WHATSAPP) {
            return $this->isWhatsAppAvailable() ? self::CHANNEL_WHATSAPP : self::CHANNEL_SMS;
        }
        
        if ($preferredChannel === self::CHANNEL_BOTH) {
            return self::CHANNEL_BOTH;
        }
        
        // AUTO mode - intelligent selection
        // 1. Check donor preference (if stored)
        $donorPreference = $donor['preferred_message_channel'] ?? null;
        if ($donorPreference === 'whatsapp' && $this->isWhatsAppAvailable()) {
            // Check if number has WhatsApp
            if ($this->checkWhatsAppNumber($donor['phone'])) {
                return self::CHANNEL_WHATSAPP;
            }
        }
        
        // 2. Prefer WhatsApp if available (better delivery, richer features)
        if ($this->isWhatsAppAvailable()) {
            // Quick check if number has WhatsApp
            if ($this->checkWhatsAppNumber($donor['phone'])) {
                return self::CHANNEL_WHATSAPP;
            }
        }
        
        // 3. Fallback to SMS
        return $this->isSMSAvailable() ? self::CHANNEL_SMS : self::CHANNEL_WHATSAPP;
    }
    
    /**
     * Check if phone number has WhatsApp (with caching)
     */
    private function checkWhatsAppNumber(string $phone): bool
    {
        if (!$this->whatsappService) {
            return false;
        }
        
        // Normalize phone
        $phone = $this->normalizePhoneNumber($phone);
        if (!$phone) {
            return false;
        }
        
        // Check cache first (store in database or session)
        $cacheKey = 'whatsapp_check_' . md5($phone);
        $cached = $this->getCachedWhatsAppCheck($phone);
        if ($cached !== null) {
            return $cached;
        }
        
        // Check via API
        $result = $this->whatsappService->checkNumber($phone);
        $hasWhatsApp = $result['success'] && ($result['has_whatsapp'] ?? false);
        
        // Cache result (24 hours)
        $this->cacheWhatsAppCheck($phone, $hasWhatsApp);
        
        return $hasWhatsApp;
    }
    
    /**
     * Get cached WhatsApp check result
     */
    private function getCachedWhatsAppCheck(string $phone): ?bool
    {
        try {
            // Check if table exists
            $check = $this->db->query("SHOW TABLES LIKE 'whatsapp_number_cache'");
            if (!$check || $check->num_rows === 0) {
                return null;
            }
            
            $stmt = $this->db->prepare("
                SELECT has_whatsapp 
                FROM whatsapp_number_cache 
                WHERE phone_number = ? 
                AND checked_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                LIMIT 1
            ");
            
            if (!$stmt) return null;
            
            $stmt->bind_param('s', $phone);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                return (bool)$row['has_whatsapp'];
            }
            
            return null;
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Cache WhatsApp check result
     */
    private function cacheWhatsAppCheck(string $phone, bool $hasWhatsApp): void
    {
        try {
            // Create table if doesn't exist
            $this->db->query("
                CREATE TABLE IF NOT EXISTS whatsapp_number_cache (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    phone_number VARCHAR(20) NOT NULL,
                    has_whatsapp TINYINT(1) NOT NULL,
                    checked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uk_phone (phone_number),
                    KEY idx_checked (checked_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            $stmt = $this->db->prepare("
                INSERT INTO whatsapp_number_cache (phone_number, has_whatsapp, checked_at)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                    has_whatsapp = VALUES(has_whatsapp),
                    checked_at = NOW()
            ");
            
            if ($stmt) {
                $stmt->bind_param('si', $phone, $hasWhatsApp);
                $stmt->execute();
            }
        } catch (Exception $e) {
            // Ignore cache errors
        }
    }
    
    /**
     * Send WhatsApp message from template
     */
    private function sendWhatsAppFromTemplate(
        string $templateKey,
        int $donorId,
        array $variables,
        string $sourceType,
        bool $queue,
        bool $forceImmediate
    ): array {
        if (!$this->whatsappService) {
            // Fallback to SMS with ENGLISH language
            return $this->smsHelper->sendFromTemplate($templateKey, $donorId, $variables, $sourceType, $queue, $forceImmediate, 'en');
        }
        
        // Get template (use SMS templates for now, can be extended)
        $template = $this->smsHelper->getTemplate($templateKey);
        if (!$template) {
            return $this->error("Template '$templateKey' not found");
        }
        
        // Get donor
        $donor = $this->getDonor($donorId);
        if (!$donor) {
            return $this->error("Donor #$donorId not found");
        }
        
        // WhatsApp: Use AMHARIC (am) as default, fallback to donor preference, then English
        $language = 'am'; // Always Amharic for WhatsApp
        $message = $template["message_am"] ?? $template["message_$language"] ?? $template['message_en'] ?? '';
        
        if (empty($message)) {
            return $this->error("Template '$templateKey' has no message content");
        }
        
        // Process variables
        if (!isset($variables['name'])) {
            $variables['name'] = $donor['name'];
        }
        
        $message = UltraMsgService::processTemplate($message, $variables);
        
        // Send WhatsApp message
        $phoneNumber = $this->normalizePhoneNumber($donor['phone']);
        if (!$phoneNumber) {
            return $this->error('Invalid donor phone number');
        }
        
        $result = $this->whatsappService->send($phoneNumber, $message, [
            'donor_id' => $donorId,
            'template_id' => $template['id'],
            'source_type' => $sourceType,
            'log' => true // Provider logs to whatsapp_log table
        ]);
        
        if ($result['success']) {
            return [
                'success' => true,
                'channel' => 'whatsapp',
                'language' => 'am',
                'message' => 'WhatsApp message sent successfully (Amharic)',
                'message_id' => $result['message_id'] ?? null
            ];
        } else {
            // Fallback to SMS with ENGLISH language
            $smsResult = $this->smsHelper->sendFromTemplate($templateKey, $donorId, $variables, $sourceType, $queue, $forceImmediate, 'en');
            // Mark as fallback
            if (isset($smsResult['success']) && $smsResult['success']) {
                $smsResult['is_fallback'] = true;
                $smsResult['fallback_reason'] = 'whatsapp_failed';
                $smsResult['original_channel'] = 'whatsapp';
                $smsResult['language'] = 'en';
            }
            return $smsResult;
        }
    }
    
    /**
     * Send WhatsApp direct message
     */
    private function sendWhatsAppDirect(
        string $phoneNumber,
        string $message,
        ?int $donorId,
        string $sourceType
    ): array {
        if (!$this->whatsappService) {
            // Fallback to SMS - SMSHelper logs via VoodooSMSService
            return $this->smsHelper->sendDirect($phoneNumber, $message, $donorId, $sourceType);
        }
        
        $result = $this->whatsappService->send($phoneNumber, $message, [
            'donor_id' => $donorId,
            'source_type' => $sourceType,
            'log' => true // Provider logs to whatsapp_log table
        ]);
        
        if ($result['success']) {
            return [
                'success' => true,
                'channel' => 'whatsapp',
                'message' => 'WhatsApp message sent successfully',
                'message_id' => $result['message_id'] ?? null
            ];
        } else {
            // Fallback to SMS - SMSHelper logs via VoodooSMSService
            $smsResult = $this->smsHelper->sendDirect($phoneNumber, $message, $donorId, $sourceType);
            return $smsResult;
        }
    }
    
    /**
     * Send via both channels
     */
    private function sendViaBothChannels(
        string $templateKey,
        int $donorId,
        array $variables,
        string $sourceType,
        bool $queue,
        bool $forceImmediate
    ): array {
        $results = [
            'success' => false,
            'channel' => 'both',
            'sms' => null,
            'whatsapp' => null
        ];
        
        // Send SMS - SMSHelper logs via VoodooSMSService
        if ($this->isSMSAvailable()) {
            $results['sms'] = $this->smsHelper->sendFromTemplate($templateKey, $donorId, $variables, $sourceType, $queue, $forceImmediate);
        }
        
        // Send WhatsApp - logs via UltraMsgService
        if ($this->isWhatsAppAvailable()) {
            $results['whatsapp'] = $this->sendWhatsAppFromTemplate($templateKey, $donorId, $variables, $sourceType, $queue, $forceImmediate);
        }
        
        // Success if at least one succeeded
        $results['success'] = 
            ($results['sms'] && $results['sms']['success']) || 
            ($results['whatsapp'] && $results['whatsapp']['success']);
        
        return $results;
    }
    
    /**
     * Send direct via both channels
     */
    private function sendDirectViaBoth(
        string $phoneNumber,
        string $message,
        ?int $donorId,
        string $sourceType
    ): array {
        $results = [
            'success' => false,
            'channel' => 'both',
            'sms' => null,
            'whatsapp' => null
        ];
        
        // Send SMS - SMSHelper logs via VoodooSMSService
        if ($this->isSMSAvailable()) {
            $results['sms'] = $this->smsHelper->sendDirect($phoneNumber, $message, $donorId, $sourceType);
        }
        
        // Send WhatsApp - logs via UltraMsgService
        if ($this->isWhatsAppAvailable()) {
            $results['whatsapp'] = $this->sendWhatsAppDirect($phoneNumber, $message, $donorId, $sourceType);
        }
        
        // Success if at least one succeeded
        $results['success'] = 
            ($results['sms'] && $results['sms']['success']) || 
            ($results['whatsapp'] && $results['whatsapp']['success']);
        
        return $results;
    }
    
    /**
     * Get donor by ID
     */
    private function getDonor(int $donorId): ?array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT id, name, phone, preferred_language, 
                       sms_opt_in, preferred_message_channel
                FROM donors 
                WHERE id = ?
            ");
            
            if (!$stmt) return null;
            
            $stmt->bind_param('i', $donorId);
            $stmt->execute();
            
            $donor = $stmt->get_result()->fetch_assoc();
            
            // Set defaults
            if ($donor) {
                $donor['preferred_language'] = $donor['preferred_language'] ?? 'en';
                $donor['sms_opt_in'] = $donor['sms_opt_in'] ?? 1;
            }
            
            return $donor ?: null;
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Normalize phone number
     */
    private function normalizePhoneNumber(string $phone): ?string
    {
        // Remove all non-numeric characters except +
        $phone = preg_replace('/[^0-9+]/', '', $phone);

        // Already full international format +XXXX... (at least 10 digits)
        if (preg_match('/^\+\d{10,15}$/', $phone)) {
            return $phone;
        }

        // Handle UK formats
        if (preg_match('/^\+44(\d{10})$/', $phone, $matches)) {
            return '+44' . $matches[1];
        }

        if (preg_match('/^44(\d{10})$/', $phone)) {
            return '+' . $phone;
        }

        if (preg_match('/^0([1-9]\d{9})$/', $phone, $matches)) {
            return '+44' . $matches[1];
        }

        if (preg_match('/^([1-9]\d{9})$/', $phone)) {
            // 10 digit without leading 0 — assume UK
            return '+44' . $phone;
        }

        // Handle Ethiopian format +251
        if (preg_match('/^251(\d{9})$/', $phone)) {
            return '+251' . substr($phone, 3);
        }

        // Any other international format without + (at least 10 digits)
        if (preg_match('/^[1-9]\d{9,14}$/', $phone)) {
            return '+' . $phone;
        }

        return null;
    }
    
    /**
     * Create error response
     */
    private function error(string $message): array
    {
        return [
            'success' => false,
            'error' => $message
        ];
    }
    
    /**
     * Log message to unified message_log table
     * 
     * @param array $data Message data to log
     * @return int|null Log entry ID or null on failure
     */
    private function logMessage(array $data): ?int
    {
        try {
            // Check if message_log table exists
            $check = $this->db->query("SHOW TABLES LIKE 'message_log'");
            if (!$check || $check->num_rows === 0) {
                // Table doesn't exist yet - that's OK, migration might not have run
                return null;
            }
            
            // Extract data with defaults
            $donorId = $data['donor_id'] ?? null;
            $phoneNumber = $data['phone_number'] ?? '';
            $recipientName = $data['recipient_name'] ?? null;
            $channel = $data['channel'] ?? 'sms';
            $messageContent = $data['message_content'] ?? '';
            $messageLanguage = $data['message_language'] ?? 'en';
            $messageLength = mb_strlen($messageContent);
            $segments = $data['segments'] ?? 1;
            
            $templateId = $data['template_id'] ?? null;
            $templateKey = $data['template_key'] ?? null;
            $templateVariables = isset($data['template_variables']) ? json_encode($data['template_variables']) : null;
            
            // Sender information
            $sentByUserId = $data['sent_by_user_id'] ?? $this->currentUserId;
            $sentByName = $data['sent_by_name'] ?? ($this->currentUser['name'] ?? null);
            $sentByRole = $data['sent_by_role'] ?? ($this->currentUser['role'] ?? null);
            
            // Source information
            $sourceType = $data['source_type'] ?? 'manual';
            $sourceId = $data['source_id'] ?? null;
            $sourceReference = $data['source_reference'] ?? null;
            
            // Provider information
            $providerId = $data['provider_id'] ?? null;
            $providerName = $data['provider_name'] ?? null;
            $providerMessageId = $data['provider_message_id'] ?? null;
            $providerResponse = isset($data['provider_response']) ? json_encode($data['provider_response']) : null;
            
            // Status
            $status = $data['status'] ?? 'sent';
            $sentAt = $data['sent_at'] ?? date('Y-m-d H:i:s');
            $deliveredAt = $data['delivered_at'] ?? null;
            $readAt = $data['read_at'] ?? null;
            $failedAt = $data['failed_at'] ?? null;
            
            // Error information
            $errorCode = $data['error_code'] ?? null;
            $errorMessage = $data['error_message'] ?? null;
            $retryCount = $data['retry_count'] ?? 0;
            $isFallback = isset($data['is_fallback']) ? (int)$data['is_fallback'] : 0;
            
            // Cost
            $costPence = $data['cost_pence'] ?? null;
            $currency = $data['currency'] ?? 'GBP';
            
            // Additional context
            $queueId = $data['queue_id'] ?? null;
            $callSessionId = $data['call_session_id'] ?? null;
            $campaignId = $data['campaign_id'] ?? null;
            $ipAddress = $data['ip_address'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);
            $userAgent = $data['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null);
            
            $stmt = $this->db->prepare("
                INSERT INTO message_log (
                    donor_id, phone_number, recipient_name, channel, message_content,
                    message_language, message_length, segments, template_id, template_key,
                    template_variables, sent_by_user_id, sent_by_name, sent_by_role,
                    source_type, source_id, source_reference, provider_id, provider_name,
                    provider_message_id, provider_response, status, sent_at, delivered_at,
                    read_at, failed_at, error_code, error_message, retry_count, is_fallback,
                    cost_pence, currency, queue_id, call_session_id, campaign_id,
                    ip_address, user_agent
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                )
            ");
            
            if (!$stmt) {
                error_log("MessagingHelper: Failed to prepare log statement: " . $this->db->error);
                return null;
            }
            
            // Bind parameters: 37 total
            // Parameter order matches INSERT statement exactly
            // Types: i=int, s=string, d=double/decimal
            $stmt->bind_param(
                'isssssiisssisssssisssssssssssssssssssssss',
                $donorId,                    // i - INT nullable
                $phoneNumber,                // s - VARCHAR(20)
                $recipientName,              // s - VARCHAR(255) nullable
                $channel,                    // s - ENUM
                $messageContent,             // s - TEXT
                $messageLanguage,            // s - ENUM
                $messageLength,              // i - INT
                $segments,                   // i - TINYINT
                $templateId,                 // i - INT UNSIGNED nullable
                $templateKey,                // s - VARCHAR(50) nullable
                $templateVariables,          // s - JSON (stored as text)
                $sentByUserId,              // i - INT nullable
                $sentByName,                // s - VARCHAR(255) nullable
                $sentByRole,                // s - VARCHAR(50) nullable
                $sourceType,                // s - VARCHAR(50)
                $sourceId,                  // i - INT nullable
                $sourceReference,           // s - VARCHAR(100) nullable
                $providerId,                // i - INT UNSIGNED nullable
                $providerName,              // s - VARCHAR(50) nullable
                $providerMessageId,         // s - VARCHAR(100) nullable
                $providerResponse,          // s - TEXT nullable
                $status,                    // s - ENUM
                $sentAt,                    // s - DATETIME
                $deliveredAt,               // s - DATETIME nullable
                $readAt,                    // s - DATETIME nullable
                $failedAt,                  // s - DATETIME nullable
                $errorCode,                 // s - VARCHAR(50) nullable
                $errorMessage,              // s - TEXT nullable
                $retryCount,                // i - TINYINT
                $isFallback,                // i - TINYINT(1)
                $costPence,                 // d - DECIMAL(8,2) nullable
                $currency,                  // s - CHAR(3)
                $queueId,                   // i - BIGINT UNSIGNED nullable
                $callSessionId,             // i - INT nullable
                $campaignId,                // i - INT nullable
                $ipAddress,                 // s - VARCHAR(45) nullable
                $userAgent                  // s - VARCHAR(255) nullable
            );
            
            if (!$stmt->execute()) {
                error_log("MessagingHelper: Failed to log message: " . $stmt->error);
                return null;
            }
            
            $logId = $stmt->insert_id;
            $stmt->close();
            
            return $logId;
            
        } catch (Exception $e) {
            error_log("MessagingHelper: Error logging message: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get comprehensive message history for a donor
     * 
     * @param int $donorId Donor ID
     * @param int|null $limit Limit results (default: 50)
     * @param int $offset Offset for pagination
     * @param string|null $channel Filter by channel (sms, whatsapp, or null for all)
     * @return array Array of message log entries
     */
    public function getDonorMessageHistory(
        int $donorId,
        ?int $limit = 50,
        int $offset = 0,
        ?string $channel = null
    ): array {
        try {
            $messages = [];
            
            // Check which tables exist
            $smsLogExists = false;
            $whatsappLogExists = false;
            
            $check = $this->db->query("SHOW TABLES LIKE 'sms_log'");
            if ($check && $check->num_rows > 0) {
                $smsLogExists = true;
            }
            
            $check = $this->db->query("SHOW TABLES LIKE 'whatsapp_log'");
            if ($check && $check->num_rows > 0) {
                $whatsappLogExists = true;
            }
            
            if (!$smsLogExists && !$whatsappLogExists) {
                return [];
            }
            
            // Get donor phone for matching
            $donorStmt = $this->db->prepare("SELECT phone FROM donors WHERE id = ?");
            $donorStmt->bind_param('i', $donorId);
            $donorStmt->execute();
            $donorResult = $donorStmt->get_result();
            $donorPhone = $donorResult->fetch_assoc()['phone'] ?? '';
            $donorStmt->close();
            
            // Build UNION query for both tables
            $unions = [];
            
            // SMS log query (source_type = 'sms' or 'voodoo' or similar)
            if ($smsLogExists && ($channel === null || $channel === 'sms')) {
                $unions[] = "
                    SELECT 
                        l.id,
                        l.donor_id,
                        l.phone_number,
                        d.name as recipient_name,
                        CASE WHEN l.source_type IN ('whatsapp', 'ultramsg') THEN 'whatsapp' ELSE 'sms' END as channel,
                        l.message_content,
                        l.message_language,
                        t.name as template_key,
                        NULL as sent_by_user_id,
                        NULL as sent_by_name,
                        NULL as sent_by_role,
                        l.source_type,
                        l.status,
                        l.sent_at,
                        NULL as delivered_at,
                        NULL as read_at,
                        NULL as failed_at,
                        l.error_message,
                        l.cost_pence,
                        0 as is_fallback
                    FROM sms_log l
                    LEFT JOIN donors d ON l.donor_id = d.id
                    LEFT JOIN sms_templates t ON l.template_id = t.id
                    WHERE (l.donor_id = {$donorId} OR l.phone_number = '{$this->db->real_escape_string($donorPhone)}')
                    " . ($channel === 'sms' ? " AND l.source_type NOT IN ('whatsapp', 'ultramsg')" : "");
            }
            
            // WhatsApp log query
            if ($whatsappLogExists && ($channel === null || $channel === 'whatsapp')) {
                $unions[] = "
                    SELECT 
                        l.id,
                        l.donor_id,
                        l.phone_number,
                        d.name as recipient_name,
                        'whatsapp' as channel,
                        l.message_content,
                        l.message_language,
                        t.name as template_key,
                        NULL as sent_by_user_id,
                        NULL as sent_by_name,
                        NULL as sent_by_role,
                        l.source_type,
                        l.status,
                        l.sent_at,
                        NULL as delivered_at,
                        NULL as read_at,
                        NULL as failed_at,
                        l.error_message,
                        0 as cost_pence,
                        0 as is_fallback
                    FROM whatsapp_log l
                    LEFT JOIN donors d ON l.donor_id = d.id
                    LEFT JOIN sms_templates t ON l.template_id = t.id
                    WHERE (l.donor_id = {$donorId} OR l.phone_number = '{$this->db->real_escape_string($donorPhone)}')
                ";
            }
            
            if (empty($unions)) {
                return [];
            }
            
            $sql = "SELECT * FROM (\n" . implode("\nUNION ALL\n", $unions) . "\n) as combined ORDER BY sent_at DESC LIMIT {$limit} OFFSET {$offset}";
            
            $result = $this->db->query($sql);
            if (!$result) {
                error_log("MessagingHelper: Query error: " . $this->db->error);
                return [];
            }
            
            while ($row = $result->fetch_assoc()) {
                // Add computed fields
                $row['delivery_time_seconds'] = null;
                $row['read_time_seconds'] = null;
                $messages[] = $row;
            }
            
            return $messages;
            
        } catch (Exception $e) {
            error_log("MessagingHelper: Error getting donor history: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get message statistics for a donor
     * 
     * @param int $donorId Donor ID
     * @return array Statistics
     */
    public function getDonorMessageStats(int $donorId): array
    {
        try {
            $stats = [
                'total_messages' => 0,
                'sms_count' => 0,
                'whatsapp_count' => 0,
                'both_count' => 0,
                'delivered_count' => 0,
                'failed_count' => 0,
                'total_cost_pence' => 0,
                'last_message_at' => null
            ];
            
            // Get donor phone for matching
            $donorStmt = $this->db->prepare("SELECT phone FROM donors WHERE id = ?");
            $donorStmt->bind_param('i', $donorId);
            $donorStmt->execute();
            $donorResult = $donorStmt->get_result();
            $donorPhone = $donorResult->fetch_assoc()['phone'] ?? '';
            $donorStmt->close();
            
            $escapedPhone = $this->db->real_escape_string($donorPhone);
            
            // Check and query sms_log
            $check = $this->db->query("SHOW TABLES LIKE 'sms_log'");
            if ($check && $check->num_rows > 0) {
                $sql = "
                    SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN source_type NOT IN ('whatsapp', 'ultramsg') THEN 1 ELSE 0 END) as sms_count,
                        SUM(CASE WHEN source_type IN ('whatsapp', 'ultramsg') THEN 1 ELSE 0 END) as whatsapp_count,
                        SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered_count,
                        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count,
                        SUM(COALESCE(cost_pence, 0)) as total_cost,
                        MAX(sent_at) as last_sent
                    FROM sms_log
                    WHERE donor_id = {$donorId} OR phone_number = '{$escapedPhone}'
                ";
                
                $result = $this->db->query($sql);
                if ($result) {
                    $row = $result->fetch_assoc();
                    $stats['total_messages'] += (int)($row['total'] ?? 0);
                    $stats['sms_count'] += (int)($row['sms_count'] ?? 0);
                    $stats['whatsapp_count'] += (int)($row['whatsapp_count'] ?? 0);
                    $stats['delivered_count'] += (int)($row['delivered_count'] ?? 0);
                    $stats['failed_count'] += (int)($row['failed_count'] ?? 0);
                    $stats['total_cost_pence'] += (float)($row['total_cost'] ?? 0);
                    if ($row['last_sent'] && (!$stats['last_message_at'] || $row['last_sent'] > $stats['last_message_at'])) {
                        $stats['last_message_at'] = $row['last_sent'];
                    }
                }
            }
            
            // Check and query whatsapp_log
            $check = $this->db->query("SHOW TABLES LIKE 'whatsapp_log'");
            if ($check && $check->num_rows > 0) {
                $sql = "
                    SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered_count,
                        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count,
                        MAX(sent_at) as last_sent
                    FROM whatsapp_log
                    WHERE donor_id = {$donorId} OR phone_number = '{$escapedPhone}'
                ";
                
                $result = $this->db->query($sql);
                if ($result) {
                    $row = $result->fetch_assoc();
                    $waTotal = (int)($row['total'] ?? 0);
                    $stats['total_messages'] += $waTotal;
                    $stats['whatsapp_count'] += $waTotal; // All entries in whatsapp_log are WhatsApp
                    $stats['delivered_count'] += (int)($row['delivered_count'] ?? 0);
                    $stats['failed_count'] += (int)($row['failed_count'] ?? 0);
                    if ($row['last_sent'] && (!$stats['last_message_at'] || $row['last_sent'] > $stats['last_message_at'])) {
                        $stats['last_message_at'] = $row['last_sent'];
                    }
                }
            }
            
            return $stats;
            
        } catch (Exception $e) {
            error_log("MessagingHelper: Error getting donor stats: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get system status
     */
    public function getStatus(): array
    {
        return [
            'sms_available' => $this->isSMSAvailable(),
            'whatsapp_available' => $this->isWhatsAppAvailable(),
            'sms_errors' => $this->smsHelper ? $this->smsHelper->getErrors() : [],
            'whatsapp_status' => $this->whatsappService ? $this->whatsappService->getStatus() : null,
            'initialized' => $this->initialized,
            'errors' => $this->errors,
            'current_user_id' => $this->currentUserId
        ];
    }
}

