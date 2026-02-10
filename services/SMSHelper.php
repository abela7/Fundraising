<?php
/**
 * SMS Helper - Modular SMS Service
 * 
 * A clean, reusable helper for sending SMS messages throughout the system.
 * Supports templates, direct messages, queueing, and logging.
 * 
 * Usage:
 *   $sms = new SMSHelper($db);
 *   $sms->sendFromTemplate('missed_call', $donorId, ['name' => 'John']);
 *   $sms->sendDirect($phoneNumber, $message);
 *   $sms->queueReminder($donorId, 'payment_reminder_3day', $variables);
 * 
 * @author Fundraising System
 * @version 1.0.0
 */

declare(strict_types=1);

require_once __DIR__ . '/VoodooSMSService.php';

class SMSHelper
{
    private $db;
    private ?VoodooSMSService $provider = null;
    private array $settings = [];
    private array $errors = [];
    private bool $initialized = false;
    
    // Default settings
    private const DEFAULT_SETTINGS = [
        'sms_daily_limit' => 1000,
        'sms_quiet_hours_start' => '21:00',
        'sms_quiet_hours_end' => '09:00',
        'sms_enabled' => '1'
    ];
    
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
     * Initialize the helper - load settings and provider
     */
    private function initialize(): void
    {
        if ($this->initialized) return;
        
        try {
            // Load settings
            $this->loadSettings();
            
            // Initialize provider
            $this->provider = VoodooSMSService::fromDatabase($this->db);
            
            $this->initialized = true;
        } catch (Exception $e) {
            $this->errors[] = 'Initialization failed: ' . $e->getMessage();
            $this->logError('init', $e->getMessage());
        }
    }
    
    /**
     * Load SMS settings from database
     */
    private function loadSettings(): void
    {
        $this->settings = self::DEFAULT_SETTINGS;
        
        try {
            $check = $this->db->query("SHOW TABLES LIKE 'sms_settings'");
            if ($check && $check->num_rows > 0) {
                $result = $this->db->query("SELECT setting_key, setting_value FROM sms_settings");
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $this->settings[$row['setting_key']] = $row['setting_value'];
                    }
                }
            }
        } catch (Exception $e) {
            $this->logError('load_settings', $e->getMessage());
        }
    }
    
    /**
     * Check if SMS system is ready to send
     * 
     * @return bool True if ready
     */
    public function isReady(): bool
    {
        return $this->initialized && $this->provider !== null;
    }
    
    /**
     * Get initialization errors
     * 
     * @return array List of errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
    
    /**
     * Send SMS using a template
     * 
     * @param string $templateKey Template key (e.g., 'missed_call', 'payment_reminder_3day')
     * @param int $donorId Donor ID
     * @param array $variables Variables to replace in template
     * @param string $sourceType Source of the SMS (e.g., 'call_center', 'cron_reminder')
     * @param bool $queue If true, queue for later processing; if false, send immediately
     * @param bool $forceImmediate If true, bypass quiet hours and send NOW (for urgent/manual sends)
     * @return array Result with 'success', 'message', 'error'
     */
    public function sendFromTemplate(
        string $templateKey, 
        int $donorId, 
        array $variables = [], 
        string $sourceType = 'system',
        bool $queue = false,
        bool $forceImmediate = false,
        ?string $languageOverride = null
    ): array {
        // Get template
        $template = $this->getTemplate($templateKey);
        if (!$template) {
            return $this->error("Template '$templateKey' not found or inactive");
        }
        
        // Get donor info
        $donor = $this->getDonor($donorId);
        if (!$donor) {
            return $this->error("Donor #$donorId not found");
        }
        
        // Check if donor can receive SMS
        $canReceive = $this->canReceiveSMS($donor);
        if (!$canReceive['success']) {
            return $canReceive;
        }
        
        // Allow caller to force language (used by WhatsApp/SMS fallback policy)
        $language = $languageOverride ?? ($donor['preferred_language'] ?? 'en');
        $message = $this->getLocalizedMessage($template, $language);
        
        // Add donor name to variables if not provided
        if (!isset($variables['name'])) {
            $variables['name'] = $donor['name'];
        }
        
        // Process template variables
        $message = VoodooSMSService::processTemplate($message, $variables);
        
        // Queue or send immediately
        if ($queue) {
            return $this->queueSMS($donorId, $donor['phone'], $message, $template['id'], $sourceType);
        } else {
            return $this->sendSMSNow($donorId, $donor['phone'], $message, $template['id'], $sourceType, $forceImmediate);
        }
    }
    
    /**
     * Send SMS directly without template
     * 
     * @param string $phoneNumber Phone number
     * @param string $message Message content
     * @param int|null $donorId Optional donor ID
     * @param string $sourceType Source type
     * @return array Result
     */
    public function sendDirect(
        string $phoneNumber, 
        string $message, 
        ?int $donorId = null,
        string $sourceType = 'manual'
    ): array {
        // Check if donor opted out (if donor ID provided)
        if ($donorId) {
            $donor = $this->getDonor($donorId);
            if ($donor) {
                $canReceive = $this->canReceiveSMS($donor);
                if (!$canReceive['success']) {
                    return $canReceive;
                }
            }
        }
        
        return $this->sendSMSNow($donorId, $phoneNumber, $message, null, $sourceType, true);
    }
    
    /**
     * Queue SMS for later sending (by cron)
     * 
     * @param int|null $donorId Donor ID
     * @param string $phoneNumber Phone number
     * @param string $message Message content
     * @param int|null $templateId Template ID
     * @param string $sourceType Source type
     * @param int $priority Priority (1-10, higher = more urgent)
     * @param string|null $scheduledFor Schedule for specific time (Y-m-d H:i:s)
     * @return array Result
     */
    public function queueSMS(
        ?int $donorId,
        string $phoneNumber,
        string $message,
        ?int $templateId = null,
        string $sourceType = 'queued',
        int $priority = 5,
        ?string $scheduledFor = null
    ): array {
        try {
            // Check if queue table exists
            $check = $this->db->query("SHOW TABLES LIKE 'sms_queue'");
            if (!$check || $check->num_rows === 0) {
                return $this->error('SMS queue table does not exist');
            }
            
            // Get donor name
            $recipientName = null;
            if ($donorId) {
                $donor = $this->getDonor($donorId);
                $recipientName = $donor['name'] ?? null;
            }
            
            $stmt = $this->db->prepare("
                INSERT INTO sms_queue 
                (donor_id, phone_number, recipient_name, template_id, message_content, 
                 message_language, source_type, priority, scheduled_for, status, created_at)
                VALUES (?, ?, ?, ?, ?, 'en', ?, ?, ?, 'pending', NOW())
            ");
            
            if (!$stmt) {
                return $this->error('Database error: ' . $this->db->error);
            }
            
            $stmt->bind_param('issisisi', 
                $donorId, $phoneNumber, $recipientName, $templateId, 
                $message, $sourceType, $priority, $scheduledFor
            );
            
            if (!$stmt->execute()) {
                return $this->error('Failed to queue SMS: ' . $stmt->error);
            }
            
            $queueId = $stmt->insert_id;
            
            $this->logActivity('queue', "SMS queued #$queueId for $phoneNumber", $donorId);
            
            return [
                'success' => true,
                'message' => 'SMS queued successfully',
                'queue_id' => $queueId
            ];
            
        } catch (Exception $e) {
            return $this->error('Queue error: ' . $e->getMessage());
        }
    }
    
    /**
     * Send SMS immediately (with optional quiet hours bypass)
     * 
     * @param bool $forceImmediate If true, send even during quiet hours (for manual/urgent sends)
     */
    private function sendSMSNow(
        ?int $donorId,
        string $phoneNumber,
        string $message,
        ?int $templateId,
        string $sourceType,
        bool $forceImmediate = false
    ): array {
        if (!$this->isReady()) {
            return $this->error('SMS system not ready. ' . implode('; ', $this->errors));
        }
        
        // Check quiet hours - but skip if forceImmediate is true
        if (!$forceImmediate && $this->isQuietHours()) {
            // Queue instead of sending
            return $this->queueSMS($donorId, $phoneNumber, $message, $templateId, $sourceType, 5, null);
        }
        
        // Check daily limit
        if (!$this->checkDailyLimit()) {
            return $this->error('Daily SMS limit reached');
        }
        
        // Check blacklist
        if ($this->isBlacklisted($phoneNumber)) {
            return $this->error('Phone number is blacklisted');
        }
        
        // Send via provider - DIRECT send, no queue!
        $result = $this->provider->send($phoneNumber, $message, [
            'donor_id' => $donorId,
            'template_id' => $templateId,
            'source_type' => $sourceType,
            'log' => true
        ]);
        
        if ($result['success']) {
            $this->logActivity('sent', "SMS sent to $phoneNumber", $donorId);
            return [
                'success' => true,
                'message' => 'SMS sent successfully',
                'message_id' => $result['message_id'] ?? null,
                'credits_used' => $result['credits_used'] ?? 1
            ];
        } else {
            $this->logActivity('failed', "SMS failed to $phoneNumber: " . ($result['error'] ?? 'Unknown'), $donorId);
            return $this->error($result['error'] ?? 'Failed to send SMS');
        }
    }
    
    /**
     * Get template by key
     */
    public function getTemplate(string $templateKey): ?array
    {
        try {
            $check = $this->db->query("SHOW TABLES LIKE 'sms_templates'");
            if (!$check || $check->num_rows === 0) {
                return null;
            }
            
            $stmt = $this->db->prepare("
                SELECT * FROM sms_templates 
                WHERE template_key = ? AND is_active = 1 
                LIMIT 1
            ");
            
            if (!$stmt) return null;
            
            $stmt->bind_param('s', $templateKey);
            $stmt->execute();
            $result = $stmt->get_result();
            
            return $result->fetch_assoc() ?: null;
            
        } catch (Exception $e) {
            $this->logError('get_template', $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get all active templates
     */
    public function getAllTemplates(): array
    {
        try {
            $check = $this->db->query("SHOW TABLES LIKE 'sms_templates'");
            if (!$check || $check->num_rows === 0) {
                return [];
            }
            
            $result = $this->db->query("
                SELECT * FROM sms_templates 
                WHERE is_active = 1 
                ORDER BY category, name
            ");
            
            $templates = [];
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $templates[] = $row;
                }
            }
            
            return $templates;
            
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * Get donor by ID
     */
    private function getDonor(int $donorId): ?array
    {
        try {
            // Check if sms_opt_in column exists
            $columns = $this->db->query("SHOW COLUMNS FROM donors LIKE 'sms_opt_in'");
            $has_opt_in = $columns && $columns->num_rows > 0;
            
            $columns_check = $this->db->query("SHOW COLUMNS FROM donors LIKE 'preferred_language'");
            $has_lang = $columns_check && $columns_check->num_rows > 0;
            
            $select = "id, name, phone";
            if ($has_lang) $select .= ", preferred_language";
            if ($has_opt_in) $select .= ", sms_opt_in";
            
            $stmt = $this->db->prepare("SELECT $select FROM donors WHERE id = ?");
            
            if (!$stmt) return null;
            
            $stmt->bind_param('i', $donorId);
            $stmt->execute();
            
            $donor = $stmt->get_result()->fetch_assoc();
            
            // Default values for missing columns
            if ($donor) {
                if (!isset($donor['preferred_language'])) $donor['preferred_language'] = 'en';
                if (!isset($donor['sms_opt_in'])) $donor['sms_opt_in'] = 1; // Default to opted-in
            }
            
            return $donor ?: null;
            
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Check if donor can receive SMS
     */
    private function canReceiveSMS(array $donor): array
    {
        // Check opt-in status
        if (!isset($donor['sms_opt_in']) || $donor['sms_opt_in'] == 0) {
            return $this->error('Donor has opted out of SMS');
        }
        
        // Check phone number
        if (empty($donor['phone'])) {
            return $this->error('Donor has no phone number');
        }
        
        // Check blacklist
        if ($this->isBlacklisted($donor['phone'])) {
            return $this->error('Phone number is blacklisted');
        }
        
        return ['success' => true];
    }
    
    /**
     * Get localized message from template
     */
    private function getLocalizedMessage(array $template, string $language): string
    {
        $langField = "message_$language";
        
        // Try preferred language first
        if (!empty($template[$langField])) {
            return $template[$langField];
        }
        
        // Fall back to English
        return $template['message_en'] ?? '';
    }
    
    /**
     * Check if phone is blacklisted
     */
    public function isBlacklisted(string $phone): bool
    {
        try {
            $check = $this->db->query("SHOW TABLES LIKE 'sms_blacklist'");
            if (!$check || $check->num_rows === 0) {
                return false;
            }
            
            $stmt = $this->db->prepare("SELECT id FROM sms_blacklist WHERE phone_number = ?");
            if (!$stmt) return false;
            
            $stmt->bind_param('s', $phone);
            $stmt->execute();
            
            return $stmt->get_result()->num_rows > 0;
            
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Check if we're in quiet hours
     */
    public function isQuietHours(): bool
    {
        $start = $this->settings['sms_quiet_hours_start'] ?? '21:00';
        $end = $this->settings['sms_quiet_hours_end'] ?? '09:00';
        
        $now = new DateTime();
        $startTime = DateTime::createFromFormat('H:i', $start);
        $endTime = DateTime::createFromFormat('H:i', $end);
        
        if (!$startTime || !$endTime) return false;
        
        // Handle overnight quiet hours (e.g., 21:00 - 09:00)
        if ($startTime > $endTime) {
            return ($now >= $startTime || $now < $endTime);
        }
        
        return ($now >= $startTime && $now < $endTime);
    }
    
    /**
     * Check daily limit
     */
    private function checkDailyLimit(): bool
    {
        $limit = (int)($this->settings['sms_daily_limit'] ?? 1000);
        
        if ($limit <= 0) return true; // Unlimited
        
        try {
            $today = date('Y-m-d');
            $result = $this->db->query("
                SELECT COUNT(*) as count FROM sms_log WHERE DATE(sent_at) = '$today'
            ");
            
            if ($result) {
                $count = (int)$result->fetch_assoc()['count'];
                return $count < $limit;
            }
            
            return true;
            
        } catch (Exception $e) {
            return true; // Allow on error
        }
    }
    
    /**
     * Log activity
     */
    private function logActivity(string $action, string $message, ?int $donorId = null): void
    {
        try {
            // Log to file
            $logDir = __DIR__ . '/../logs';
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }
            
            $logFile = $logDir . '/sms-' . date('Y-m-d') . '.log';
            $timestamp = date('Y-m-d H:i:s');
            $logEntry = "[$timestamp] [$action] $message" . ($donorId ? " (Donor: $donorId)" : "") . "\n";
            
            @file_put_contents($logFile, $logEntry, FILE_APPEND);
            
        } catch (Exception $e) {
            // Ignore logging errors
        }
    }
    
    /**
     * Log error
     */
    private function logError(string $context, string $message): void
    {
        $this->logActivity('error', "[$context] $message");
        error_log("SMSHelper Error [$context]: $message");
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
     * Get provider credit balance
     */
    public function getBalance(): array
    {
        if (!$this->isReady()) {
            return ['success' => false, 'credits' => 0, 'error' => 'SMS system not ready'];
        }
        
        return $this->provider->getBalance();
    }
    
    /**
     * Get SMS statistics
     */
    public function getStats(): array
    {
        $stats = [
            'today_sent' => 0,
            'today_failed' => 0,
            'today_cost' => 0,
            'pending_queue' => 0,
            'credits_remaining' => 0
        ];
        
        try {
            $today = date('Y-m-d');
            
            // Today's stats
            $result = $this->db->query("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status IN ('sent', 'delivered') THEN 1 ELSE 0 END) as sent,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                    COALESCE(SUM(cost_pence), 0) as cost
                FROM sms_log
                WHERE DATE(sent_at) = '$today'
            ");
            
            if ($result && $row = $result->fetch_assoc()) {
                $stats['today_sent'] = (int)$row['sent'];
                $stats['today_failed'] = (int)$row['failed'];
                $stats['today_cost'] = (float)$row['cost'];
            }
            
            // Queue stats
            $result = $this->db->query("
                SELECT COUNT(*) as count FROM sms_queue WHERE status = 'pending'
            ");
            if ($result && $row = $result->fetch_assoc()) {
                $stats['pending_queue'] = (int)$row['count'];
            }
            
            // Credits
            $balance = $this->getBalance();
            if ($balance['success']) {
                $stats['credits_remaining'] = $balance['credits'];
            }
            
        } catch (Exception $e) {
            // Return default stats on error
        }
        
        return $stats;
    }
}

