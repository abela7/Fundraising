<?php
/**
 * Centralized Audit Logging Helper
 * 
 * This helper provides a consistent way to log all activities in the system,
 * especially database operations (INSERT, UPDATE, DELETE).
 */

declare(strict_types=1);

/**
 * Log an audit event to the audit_logs table
 * 
 * @param mysqli $db Database connection
 * @param string $action Action type (e.g., 'create', 'update', 'delete', 'approve', 'login', etc.)
 * @param string $entityType Entity type (e.g., 'donor', 'payment', 'pledge', 'user', etc.)
 * @param int $entityId ID of the entity being acted upon
 * @param array|null $beforeData State before the change (null for creates)
 * @param array|null $afterData State after the change (null for deletes)
 * @param string $source Source of the action (e.g., 'admin_portal', 'donor_portal', 'registrar_portal', 'api', 'system')
 * @param int|null $userId User ID performing the action (null for system actions)
 * @param string|null $ipAddress IP address (will be auto-detected if null)
 * @return bool Success status
 */
function log_audit(
    mysqli $db,
    string $action,
    string $entityType,
    int $entityId,
    ?array $beforeData = null,
    ?array $afterData = null,
    string $source = 'system',
    ?int $userId = null,
    ?string $ipAddress = null
): bool {
    try {
        // Auto-detect user ID if not provided
        if ($userId === null && isset($_SESSION['user_id'])) {
            $userId = (int)$_SESSION['user_id'];
        } elseif ($userId === null && isset($_SESSION['donor'])) {
            $userId = 0; // Donor actions use user_id = 0
        }
        
        // Auto-detect IP address if not provided
        if ($ipAddress === null) {
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        }
        
        // Convert IP to binary format for storage
        $ipBinary = null;
        if ($ipAddress) {
            $ipBinary = @inet_pton($ipAddress);
            if ($ipBinary === false) {
                $ipBinary = null;
            }
        }
        
        // Prepare JSON data
        $beforeJson = $beforeData ? json_encode($beforeData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
        $afterJson = $afterData ? json_encode($afterData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
        
        // Insert audit log
        $stmt = $db->prepare("
            INSERT INTO audit_logs (
                user_id, entity_type, entity_id, action, 
                before_json, after_json, source, ip_address, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        // Handle binary IP address binding
        if ($ipBinary !== null) {
            $stmt->bind_param(
                'isisssss',
                $userId,
                $entityType,
                $entityId,
                $action,
                $beforeJson,
                $afterJson,
                $source,
                $ipBinary
            );
        } else {
            $stmt->bind_param(
                'isissss',
                $userId,
                $entityType,
                $entityId,
                $action,
                $beforeJson,
                $afterJson,
                $source
            );
        }
        
        $result = $stmt->execute();
        $stmt->close();
        
        return $result;
    } catch (Exception $e) {
        error_log("Audit logging failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Helper to get current user ID from session
 */
function get_current_user_id(): ?int {
    if (isset($_SESSION['user_id'])) {
        return (int)$_SESSION['user_id'];
    }
    return null;
}

/**
 * Helper to get current source context
 */
function get_current_source(): string {
    if (strpos($_SERVER['PHP_SELF'] ?? '', '/admin/') !== false) {
        return 'admin_portal';
    } elseif (strpos($_SERVER['PHP_SELF'] ?? '', '/donor/') !== false) {
        return 'donor_portal';
    } elseif (strpos($_SERVER['PHP_SELF'] ?? '', '/registrar/') !== false) {
        return 'registrar_portal';
    } elseif (strpos($_SERVER['PHP_SELF'] ?? '', '/api/') !== false || strpos($_SERVER['PHP_SELF'] ?? '', '/webhooks/') !== false) {
        return 'api';
    } elseif (strpos($_SERVER['PHP_SELF'] ?? '', '/cron/') !== false) {
        return 'cron';
    }
    return 'system';
}

