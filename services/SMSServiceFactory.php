<?php
/**
 * SMS Service Factory
 * 
 * Returns the appropriate SMS service based on database configuration.
 * Supports multiple providers with automatic failover.
 * 
 * @author Fundraising System
 */

declare(strict_types=1);

require_once __DIR__ . '/VoodooSMSService.php';
require_once __DIR__ . '/TheSMSWorksService.php';

class SMSServiceFactory
{
    private static ?object $cachedService = null;
    private static ?int $cachedProviderId = null;
    
    /**
     * Get the default SMS service from database
     * 
     * @param mysqli $db Database connection
     * @param bool $forceRefresh Bypass cache and get fresh instance
     * @return VoodooSMSService|TheSMSWorksService|null
     */
    public static function getDefaultService($db, bool $forceRefresh = false): ?object
    {
        // Return cached instance if available
        if (!$forceRefresh && self::$cachedService !== null) {
            return self::$cachedService;
        }
        
        try {
            // Check if table exists
            $check = $db->query("SHOW TABLES LIKE 'sms_providers'");
            if (!$check || $check->num_rows === 0) {
                return null;
            }
            
            // Get default active provider
            $result = $db->query("
                SELECT id, name, api_key, api_secret, sender_id 
                FROM sms_providers 
                WHERE is_active = 1 
                ORDER BY is_default DESC, id ASC
                LIMIT 1
            ");
            
            if (!$result || $result->num_rows === 0) {
                return null;
            }
            
            $provider = $result->fetch_assoc();
            
            self::$cachedProviderId = (int)$provider['id'];
            self::$cachedService = self::createService(
                $provider['name'],
                $provider['api_key'],
                $provider['api_secret'],
                $provider['sender_id'] ?: 'ATEOTC',
                $db,
                (int)$provider['id']
            );
            
            return self::$cachedService;
            
        } catch (Exception $e) {
            error_log("SMSServiceFactory: Failed to get default service - " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get SMS service by provider name
     * 
     * @param mysqli $db Database connection
     * @param string $providerName Provider name (voodoosms, thesmsworks)
     * @return VoodooSMSService|TheSMSWorksService|null
     */
    public static function getServiceByName($db, string $providerName): ?object
    {
        try {
            // Check if table exists
            $check = $db->query("SHOW TABLES LIKE 'sms_providers'");
            if (!$check || $check->num_rows === 0) {
                return null;
            }
            
            $stmt = $db->prepare("
                SELECT id, name, api_key, api_secret, sender_id 
                FROM sms_providers 
                WHERE name = ? AND is_active = 1 
                LIMIT 1
            ");
            
            if (!$stmt) {
                return null;
            }
            
            $stmt->bind_param('s', $providerName);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                return null;
            }
            
            $provider = $result->fetch_assoc();
            
            return self::createService(
                $provider['name'],
                $provider['api_key'],
                $provider['api_secret'],
                $provider['sender_id'] ?: 'ATEOTC',
                $db,
                (int)$provider['id']
            );
            
        } catch (Exception $e) {
            error_log("SMSServiceFactory: Failed to get service by name - " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get SMS service by provider ID
     * 
     * @param mysqli $db Database connection
     * @param int $providerId Provider ID
     * @return VoodooSMSService|TheSMSWorksService|null
     */
    public static function getServiceById($db, int $providerId): ?object
    {
        try {
            // Check if table exists
            $check = $db->query("SHOW TABLES LIKE 'sms_providers'");
            if (!$check || $check->num_rows === 0) {
                return null;
            }
            
            $stmt = $db->prepare("
                SELECT id, name, api_key, api_secret, sender_id 
                FROM sms_providers 
                WHERE id = ? AND is_active = 1 
                LIMIT 1
            ");
            
            if (!$stmt) {
                return null;
            }
            
            $stmt->bind_param('i', $providerId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                return null;
            }
            
            $provider = $result->fetch_assoc();
            
            return self::createService(
                $provider['name'],
                $provider['api_key'],
                $provider['api_secret'],
                $provider['sender_id'] ?: 'ATEOTC',
                $db,
                (int)$provider['id']
            );
            
        } catch (Exception $e) {
            error_log("SMSServiceFactory: Failed to get service by ID - " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Create SMS service instance
     * 
     * @param string $name Provider name
     * @param string $apiKey API key/username
     * @param string $apiSecret API secret/password
     * @param string $senderId Sender ID
     * @param mysqli $db Database connection
     * @param int $providerId Provider ID
     * @return VoodooSMSService|TheSMSWorksService|null
     */
    private static function createService(
        string $name,
        string $apiKey,
        string $apiSecret,
        string $senderId,
        $db,
        int $providerId
    ): ?object {
        switch (strtolower($name)) {
            case 'voodoosms':
                return new VoodooSMSService($apiKey, $apiSecret, $senderId, $db, $providerId);
                
            case 'thesmsworks':
                return new TheSMSWorksService($apiKey, $apiSecret, $senderId, $db, $providerId);
                
            default:
                error_log("SMSServiceFactory: Unknown provider - $name");
                return null;
        }
    }
    
    /**
     * Get list of all active providers from database
     * 
     * @param mysqli $db Database connection
     * @return array Array of provider info
     */
    public static function getActiveProviders($db): array
    {
        try {
            $check = $db->query("SHOW TABLES LIKE 'sms_providers'");
            if (!$check || $check->num_rows === 0) {
                return [];
            }
            
            $result = $db->query("
                SELECT id, name, display_name, is_default, last_success_at, failure_count
                FROM sms_providers 
                WHERE is_active = 1 
                ORDER BY is_default DESC, name ASC
            ");
            
            if (!$result) {
                return [];
            }
            
            $providers = [];
            while ($row = $result->fetch_assoc()) {
                $providers[] = $row;
            }
            
            return $providers;
            
        } catch (Exception $e) {
            error_log("SMSServiceFactory: Failed to get active providers - " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get available provider definitions (supported providers)
     * 
     * @return array Provider definitions
     */
    public static function getSupportedProviders(): array
    {
        return [
            'voodoosms' => [
                'name' => 'voodoosms',
                'display_name' => 'VoodooSMS',
                'description' => 'UK-based SMS provider with direct network routes.',
                'website' => 'https://www.voodoosms.com',
                'docs' => 'https://help.voodoosms.com/en/categories/15-rest-api',
                'fields' => [
                    'api_key' => ['label' => 'Username', 'type' => 'text', 'required' => true],
                    'api_secret' => ['label' => 'Password', 'type' => 'password', 'required' => true],
                    'sender_id' => ['label' => 'Sender ID', 'type' => 'text', 'required' => true, 'maxlength' => 11]
                ],
                'default_cost' => 3.5 // pence per SMS
            ],
            'thesmsworks' => [
                'name' => 'thesmsworks',
                'display_name' => 'The SMS Works',
                'description' => 'UK SMS gateway with pay-per-delivery. Failed UK messages refunded.',
                'website' => 'https://thesmsworks.co.uk',
                'docs' => 'https://thesmsworks.co.uk/developers',
                'fields' => [
                    'api_key' => ['label' => 'Customer ID', 'type' => 'text', 'required' => true],
                    'api_secret' => ['label' => 'API Key (Secret)', 'type' => 'password', 'required' => true],
                    'sender_id' => ['label' => 'Sender ID', 'type' => 'text', 'required' => true, 'maxlength' => 11]
                ],
                'default_cost' => 2.9 // pence per SMS (typically cheaper)
            ]
        ];
    }
    
    /**
     * Clear cached service
     */
    public static function clearCache(): void
    {
        self::$cachedService = null;
        self::$cachedProviderId = null;
    }
    
    /**
     * Test a provider's connection
     * 
     * @param string $name Provider name
     * @param string $apiKey API key
     * @param string $apiSecret API secret
     * @param string $senderId Sender ID
     * @return array Test result
     */
    public static function testConnection(
        string $name,
        string $apiKey,
        string $apiSecret,
        string $senderId
    ): array {
        $service = self::createService($name, $apiKey, $apiSecret, $senderId, null, 0);
        
        if (!$service) {
            return [
                'success' => false,
                'message' => 'Unknown provider: ' . $name
            ];
        }
        
        return $service->testConnection();
    }
}

