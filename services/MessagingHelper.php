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

class MessagingHelper
{
    private $db;
    private ?SMSHelper $smsHelper = null;
    private ?UltraMsgService $whatsappService = null;
    private array $errors = [];
    private bool $initialized = false;
    
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
            
            $this->initialized = true;
        } catch (Exception $e) {
            $this->errors[] = 'Initialization failed: ' . $e->getMessage();
            error_log("MessagingHelper init error: " . $e->getMessage());
        }
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
        
        // Quick status check
        $status = $this->whatsappService->getStatus();
        return $status['success'] && ($status['status'] === 'authenticated' || $status['status'] === 'connected');
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
            return $this->smsHelper->sendFromTemplate($templateKey, $donorId, $variables, $sourceType, $queue, $forceImmediate);
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
            return $this->smsHelper->sendDirect($phoneNumber, $message, $donorId, $sourceType);
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
            // Fallback to SMS
            return $this->smsHelper->sendFromTemplate($templateKey, $donorId, $variables, $sourceType, $queue, $forceImmediate);
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
        
        // Get localized message
        $language = $donor['preferred_language'] ?? 'en';
        $message = $template["message_$language"] ?? $template['message_en'] ?? '';
        
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
            'log' => true
        ]);
        
        if ($result['success']) {
            return [
                'success' => true,
                'channel' => 'whatsapp',
                'message' => 'WhatsApp message sent successfully',
                'message_id' => $result['message_id'] ?? null
            ];
        } else {
            // Fallback to SMS on failure
            return $this->smsHelper->sendFromTemplate($templateKey, $donorId, $variables, $sourceType, $queue, $forceImmediate);
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
            // Fallback to SMS
            return $this->smsHelper->sendDirect($phoneNumber, $message, $donorId, $sourceType);
        }
        
        $result = $this->whatsappService->send($phoneNumber, $message, [
            'donor_id' => $donorId,
            'source_type' => $sourceType,
            'log' => true
        ]);
        
        if ($result['success']) {
            return [
                'success' => true,
                'channel' => 'whatsapp',
                'message' => 'WhatsApp message sent successfully',
                'message_id' => $result['message_id'] ?? null
            ];
        } else {
            // Fallback to SMS
            return $this->smsHelper->sendDirect($phoneNumber, $message, $donorId, $sourceType);
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
        
        // Send SMS
        if ($this->isSMSAvailable()) {
            $results['sms'] = $this->smsHelper->sendFromTemplate($templateKey, $donorId, $variables, $sourceType, $queue, $forceImmediate);
        }
        
        // Send WhatsApp
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
        
        // Send SMS
        if ($this->isSMSAvailable()) {
            $results['sms'] = $this->smsHelper->sendDirect($phoneNumber, $message, $donorId, $sourceType);
        }
        
        // Send WhatsApp
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
            return '+44' . $phone;
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
            'errors' => $this->errors
        ];
    }
}

