<?php
/**
 * Twilio Error Code Mappings
 * 
 * Translates Twilio error codes into human-readable messages
 * Reference: https://www.twilio.com/docs/api/errors
 */

declare(strict_types=1);

class TwilioErrorCodes
{
    /**
     * Get human-readable error message from Twilio error code
     * 
     * @param string|null $errorCode
     * @return array ['category' => string, 'message' => string, 'action' => string]
     */
    public static function getErrorInfo(?string $errorCode): array
    {
        if ($errorCode === null) {
            return [
                'category' => 'Unknown',
                'message' => 'No error code provided',
                'action' => 'Check call logs for more details'
            ];
        }
        
        $errorMap = [
            // Call Progress Errors (30000-30999)
            '30001' => [
                'category' => 'Queue Overflow',
                'message' => 'Too many concurrent calls',
                'action' => 'Try again in a few minutes'
            ],
            '30002' => [
                'category' => 'Account Suspended',
                'message' => 'Twilio account suspended',
                'action' => 'Contact Twilio support immediately'
            ],
            '30003' => [
                'category' => 'Unreachable',
                'message' => 'Destination unreachable',
                'action' => 'Verify the phone number is correct'
            ],
            '30004' => [
                'category' => 'Message Blocked',
                'message' => 'Call blocked by Twilio',
                'action' => 'Contact Twilio support'
            ],
            '30005' => [
                'category' => 'Unknown Destination',
                'message' => 'Phone number does not exist',
                'action' => 'Verify and update donor phone number'
            ],
            '30006' => [
                'category' => 'Landline/Unreachable',
                'message' => 'Cannot reach landline or mobile is off',
                'action' => 'Try calling at a different time'
            ],
            '30007' => [
                'category' => 'Carrier Violation',
                'message' => 'Call rejected by carrier',
                'action' => 'Contact donor via different method'
            ],
            '30008' => [
                'category' => 'Region Blocked',
                'message' => 'Calls to this region are blocked',
                'action' => 'Enable region in Twilio settings'
            ],
            
            // Call Execution Errors (31000-31999)
            '31000' => [
                'category' => 'Call Rejected',
                'message' => 'Call rejected by recipient',
                'action' => 'Donor may have blocked this number'
            ],
            '31002' => [
                'category' => 'Number Format',
                'message' => 'Invalid phone number format',
                'action' => 'Update phone number to E.164 format'
            ],
            '31003' => [
                'category' => 'International Disabled',
                'message' => 'International calling not enabled',
                'action' => 'Enable in Twilio console'
            ],
            '31005' => [
                'category' => 'Network Error',
                'message' => 'Network connection error',
                'action' => 'Retry the call - temporary network issue'
            ],
            '31009' => [
                'category' => 'Bad Request',
                'message' => 'Invalid request parameters',
                'action' => 'Check system configuration'
            ],
            
            // SIP Errors (32000-32999)
            '32000' => [
                'category' => 'SIP Error',
                'message' => 'SIP protocol error',
                'action' => 'System error - contact support'
            ],
            
            // Address Errors (33000-33999)
            '33001' => [
                'category' => 'Invalid Number',
                'message' => 'Phone number is invalid',
                'action' => 'Update donor phone number'
            ],
            '33002' => [
                'category' => 'Number Not Found',
                'message' => 'Phone number not found',
                'action' => 'Verify phone number exists'
            ],
            
            // HTTP Errors (11000-11999)
            '11200' => [
                'category' => 'HTTP Error',
                'message' => 'Webhook returned error',
                'action' => 'Check webhook endpoint configuration'
            ],
            '11210' => [
                'category' => 'HTTP Error',
                'message' => 'Webhook timeout',
                'action' => 'Optimize webhook response time'
            ],
            
            // Voice Errors (13000-13999)
            '13225' => [
                'category' => 'Invalid TwiML',
                'message' => 'TwiML response is invalid',
                'action' => 'Check webhook TwiML generation'
            ],
            '13227' => [
                'category' => 'TwiML Error',
                'message' => 'TwiML execution error',
                'action' => 'Check webhook logic'
            ],
            
            // Common SIP Status Codes
            '486' => [
                'category' => 'Busy',
                'message' => 'Donor line is busy',
                'action' => 'Schedule callback for later'
            ],
            '487' => [
                'category' => 'Canceled',
                'message' => 'Call was canceled',
                'action' => 'Call was ended before connection'
            ],
            '603' => [
                'category' => 'Declined',
                'message' => 'Call declined by recipient',
                'action' => 'Donor rejected the call'
            ],
            '480' => [
                'category' => 'Unavailable',
                'message' => 'Temporarily unavailable',
                'action' => 'Phone may be off or out of coverage'
            ],
        ];
        
        if (isset($errorMap[$errorCode])) {
            return $errorMap[$errorCode];
        }
        
        // Generic error
        return [
            'category' => 'Error ' . $errorCode,
            'message' => 'Call failed with error code: ' . $errorCode,
            'action' => 'Check Twilio documentation for error code ' . $errorCode
        ];
    }
    
    /**
     * Check if error is retryable
     * 
     * @param string|null $errorCode
     * @return bool
     */
    public static function isRetryable(?string $errorCode): bool
    {
        if ($errorCode === null) {
            return false;
        }
        
        // Network errors and temporary issues are retryable
        $retryableErrors = [
            '31005', // Network error
            '30001', // Queue overflow
            '30006', // Unreachable (might be temporary)
            '480',   // Temporarily unavailable
        ];
        
        return in_array($errorCode, $retryableErrors);
    }
    
    /**
     * Check if error indicates bad phone number
     * 
     * @param string|null $errorCode
     * @return bool
     */
    public static function isBadNumber(?string $errorCode): bool
    {
        if ($errorCode === null) {
            return false;
        }
        
        $badNumberErrors = [
            '30005', // Unknown destination
            '31002', // Invalid format
            '33001', // Invalid number
            '33002', // Number not found
        ];
        
        return in_array($errorCode, $badNumberErrors);
    }
    
    /**
     * Get recommended action based on error code
     * 
     * @param string|null $errorCode
     * @return string One of: 'retry', 'update_number', 'skip', 'escalate'
     */
    public static function getRecommendedAction(?string $errorCode): string
    {
        if ($errorCode === null) {
            return 'skip';
        }
        
        if (self::isRetryable($errorCode)) {
            return 'retry';
        }
        
        if (self::isBadNumber($errorCode)) {
            return 'update_number';
        }
        
        // Blocked, rejected, or system errors
        if (in_array($errorCode, ['31000', '603', '30002', '30007'])) {
            return 'escalate';
        }
        
        return 'skip';
    }
}

