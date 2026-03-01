<?php
declare(strict_types=1);

/**
 * Authentication hardening helpers:
 * - per-IP / per-identifier throttling
 * - failed-attempt tracking with hashed identifiers
 */

const LOGIN_SECURITY_WINDOW_MINUTES = 15;
const LOGIN_SECURITY_MAX_IP_FAILURES = 25;
const LOGIN_SECURITY_MAX_IDENTIFIER_FAILURES = 10;
const LOGIN_SECURITY_MAX_PAIR_FAILURES = 6;

function login_security_get_ip(): string
{
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    if ($ip === '') {
        $ip = 'unknown';
    }
    return substr($ip, 0, 45);
}

function login_security_normalize_identifier(string $identifier): string
{
    $value = trim($identifier);
    if ($value === '') {
        return 'empty';
    }

    $digits = preg_replace('/\D+/', '', $value);
    if (is_string($digits) && $digits !== '') {
        return $digits;
    }

    return strtolower($value);
}

function login_security_bootstrap(mysqli $db): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $sql = "
        CREATE TABLE IF NOT EXISTS auth_login_attempts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            scope VARCHAR(40) NOT NULL,
            identifier_hash CHAR(64) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_scope_identifier_time (scope, identifier_hash, attempted_at),
            INDEX idx_scope_ip_time (scope, ip_address, attempted_at),
            INDEX idx_attempted_at (attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    $db->query($sql);
    $initialized = true;
}

function login_security_check(mysqli $db, string $scope, string $identifier, string $ip): array
{
    try {
        login_security_bootstrap($db);
        $db->query("DELETE FROM auth_login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 2 DAY)");

        $windowMinutes = LOGIN_SECURITY_WINDOW_MINUTES;
        $identifierHash = hash('sha256', login_security_normalize_identifier($identifier));

        $ipStmt = $db->prepare("
            SELECT COUNT(*) AS fail_count, UNIX_TIMESTAMP(MAX(attempted_at)) AS last_ts
            FROM auth_login_attempts
            WHERE scope = ? AND ip_address = ? AND success = 0
              AND attempted_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
        ");
        $ipStmt->bind_param('ssi', $scope, $ip, $windowMinutes);
        $ipStmt->execute();
        $ipRow = $ipStmt->get_result()->fetch_assoc() ?: ['fail_count' => 0, 'last_ts' => null];
        $ipStmt->close();

        $idStmt = $db->prepare("
            SELECT COUNT(*) AS fail_count, UNIX_TIMESTAMP(MAX(attempted_at)) AS last_ts
            FROM auth_login_attempts
            WHERE scope = ? AND identifier_hash = ? AND success = 0
              AND attempted_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
        ");
        $idStmt->bind_param('ssi', $scope, $identifierHash, $windowMinutes);
        $idStmt->execute();
        $idRow = $idStmt->get_result()->fetch_assoc() ?: ['fail_count' => 0, 'last_ts' => null];
        $idStmt->close();

        $pairStmt = $db->prepare("
            SELECT COUNT(*) AS fail_count, UNIX_TIMESTAMP(MAX(attempted_at)) AS last_ts
            FROM auth_login_attempts
            WHERE scope = ? AND identifier_hash = ? AND ip_address = ? AND success = 0
              AND attempted_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
        ");
        $pairStmt->bind_param('sssi', $scope, $identifierHash, $ip, $windowMinutes);
        $pairStmt->execute();
        $pairRow = $pairStmt->get_result()->fetch_assoc() ?: ['fail_count' => 0, 'last_ts' => null];
        $pairStmt->close();

        $blocked = false;
        $lastTs = 0;

        if ((int)$ipRow['fail_count'] >= LOGIN_SECURITY_MAX_IP_FAILURES) {
            $blocked = true;
            $lastTs = max($lastTs, (int)($ipRow['last_ts'] ?? 0));
        }
        if ((int)$idRow['fail_count'] >= LOGIN_SECURITY_MAX_IDENTIFIER_FAILURES) {
            $blocked = true;
            $lastTs = max($lastTs, (int)($idRow['last_ts'] ?? 0));
        }
        if ((int)$pairRow['fail_count'] >= LOGIN_SECURITY_MAX_PAIR_FAILURES) {
            $blocked = true;
            $lastTs = max($lastTs, (int)($pairRow['last_ts'] ?? 0));
        }

        if (!$blocked) {
            return ['blocked' => false, 'retry_after' => 0];
        }

        $retryAfter = max(1, (($lastTs + (LOGIN_SECURITY_WINDOW_MINUTES * 60)) - time()));
        return ['blocked' => true, 'retry_after' => $retryAfter];
    } catch (Throwable $e) {
        error_log('login_security_check failed: ' . $e->getMessage());
        return ['blocked' => false, 'retry_after' => 0];
    }
}

function login_security_record(mysqli $db, string $scope, string $identifier, string $ip, bool $success): void
{
    try {
        login_security_bootstrap($db);
        $identifierHash = hash('sha256', login_security_normalize_identifier($identifier));
        $ok = $success ? 1 : 0;

        $stmt = $db->prepare("
            INSERT INTO auth_login_attempts (scope, identifier_hash, ip_address, success, attempted_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param('sssi', $scope, $identifierHash, $ip, $ok);
        $stmt->execute();
        $stmt->close();

        if ($success) {
            $cleanup = $db->prepare("
                DELETE FROM auth_login_attempts
                WHERE scope = ? AND success = 0
                  AND (identifier_hash = ? OR ip_address = ?)
                  AND attempted_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
            ");
            $cleanup->bind_param('sss', $scope, $identifierHash, $ip);
            $cleanup->execute();
            $cleanup->close();
        }
    } catch (Throwable $e) {
        error_log('login_security_record failed: ' . $e->getMessage());
    }
}

function login_security_retry_message(int $seconds): string
{
    if ($seconds <= 60) {
        return 'Please wait about 1 minute before trying again.';
    }

    $minutes = (int)ceil($seconds / 60);
    return 'Too many attempts. Please wait about ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . ' and try again.';
}
