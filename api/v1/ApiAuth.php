<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/ApiResponse.php';

/**
 * API Token Authentication
 * 
 * Handles token-based authentication for the PWA API.
 * Uses access tokens (short-lived) and refresh tokens (long-lived).
 */
class ApiAuth
{
    private const ACCESS_TOKEN_EXPIRY = 900; // 15 minutes
    private const REFRESH_TOKEN_EXPIRY = 2592000; // 30 days
    private const TOKEN_LENGTH = 64; // 64 bytes = 128 hex chars

    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?? db();
    }

    /**
     * Authenticate a donor by phone and OTP code
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int, user: array}
     */
    public function authenticateDonor(string $phone, string $otpCode): array
    {
        // Normalize phone
        $normalizedPhone = $this->normalizePhone($phone);
        
        // Verify OTP
        if (!$this->verifyDonorOtp($normalizedPhone, $otpCode)) {
            ApiResponse::error('Invalid or expired OTP code', 401, 'INVALID_OTP');
        }

        // Get donor
        $donor = $this->getDonorByPhone($normalizedPhone);
        if (!$donor) {
            ApiResponse::error('Donor not found', 404, 'DONOR_NOT_FOUND');
        }

        // Generate tokens
        return $this->createTokens('donor', (int) $donor['id'], $donor);
    }

    /**
     * Authenticate admin/registrar by phone and password
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int, user: array}
     */
    public function authenticateUser(string $phone, string $password): array
    {
        $normalizedPhone = preg_replace('/\D+/', '', $phone);
        
        // Get user
        $stmt = $this->db->prepare(
            "SELECT id, name, phone, role, password_hash, active 
             FROM users WHERE phone = ? OR 
             REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', '') = ?
             LIMIT 1"
        );
        $stmt->bind_param('ss', $phone, $normalizedPhone);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user || (int) $user['active'] !== 1) {
            ApiResponse::error('Invalid credentials', 401, 'INVALID_CREDENTIALS');
        }

        if (!password_verify($password, $user['password_hash'])) {
            ApiResponse::error('Invalid credentials', 401, 'INVALID_CREDENTIALS');
        }

        $userType = $user['role'] === 'admin' ? 'admin' : 'registrar';
        
        return $this->createTokens($userType, (int) $user['id'], [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'phone' => $user['phone'],
            'role' => $user['role'],
        ]);
    }

    /**
     * Validate access token and return user data
     *
     * @return array{user_type: string, user_id: int, user: array}
     */
    public function validateToken(string $accessToken): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, user_type, user_id, access_expires_at 
             FROM api_tokens 
             WHERE access_token = ? AND is_revoked = 0 AND access_expires_at > NOW()
             LIMIT 1"
        );
        $stmt->bind_param('s', $accessToken);
        $stmt->execute();
        $token = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$token) {
            return null;
        }

        // Update last used
        $updateStmt = $this->db->prepare(
            "UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?"
        );
        $updateStmt->bind_param('i', $token['id']);
        $updateStmt->execute();
        $updateStmt->close();

        // Get user data
        $user = $this->getUserData($token['user_type'], (int) $token['user_id']);

        return [
            'user_type' => $token['user_type'],
            'user_id' => (int) $token['user_id'],
            'user' => $user,
        ];
    }

    /**
     * Refresh access token using refresh token
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     */
    public function refreshToken(string $refreshToken): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, user_type, user_id, refresh_expires_at 
             FROM api_tokens 
             WHERE refresh_token = ? AND is_revoked = 0 AND refresh_expires_at > NOW()
             LIMIT 1"
        );
        $stmt->bind_param('s', $refreshToken);
        $stmt->execute();
        $token = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$token) {
            ApiResponse::error('Invalid or expired refresh token', 401, 'INVALID_REFRESH_TOKEN');
        }

        // Revoke old token
        $revokeStmt = $this->db->prepare("UPDATE api_tokens SET is_revoked = 1 WHERE id = ?");
        $revokeStmt->bind_param('i', $token['id']);
        $revokeStmt->execute();
        $revokeStmt->close();

        // Get user data for response
        $user = $this->getUserData($token['user_type'], (int) $token['user_id']);

        // Create new tokens
        return $this->createTokens($token['user_type'], (int) $token['user_id'], $user);
    }

    /**
     * Revoke a token (logout)
     */
    public function revokeToken(string $accessToken): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE api_tokens SET is_revoked = 1 WHERE access_token = ?"
        );
        $stmt->bind_param('s', $accessToken);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        return $affected > 0;
    }

    /**
     * Revoke all tokens for a user
     */
    public function revokeAllTokens(string $userType, int $userId): int
    {
        $stmt = $this->db->prepare(
            "UPDATE api_tokens SET is_revoked = 1 WHERE user_type = ? AND user_id = ?"
        );
        $stmt->bind_param('si', $userType, $userId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        return $affected;
    }

    /**
     * Require authentication - call at start of protected endpoints
     *
     * @return array{user_type: string, user_id: int, user: array}
     */
    public function requireAuth(): array
    {
        $token = $this->extractBearerToken();
        
        if (!$token) {
            ApiResponse::error('Authorization required', 401, 'NO_TOKEN');
        }

        $auth = $this->validateToken($token);
        
        if (!$auth) {
            ApiResponse::error('Invalid or expired token', 401, 'INVALID_TOKEN');
        }

        return $auth;
    }

    /**
     * Require specific user type(s)
     *
     * @param string|array $allowedTypes Single type or array of allowed types
     * @return array{user_type: string, user_id: int, user: array}
     */
    public function requireRole(string|array $allowedTypes): array
    {
        $auth = $this->requireAuth();
        
        $allowed = is_array($allowedTypes) ? $allowedTypes : [$allowedTypes];
        
        if (!in_array($auth['user_type'], $allowed, true)) {
            ApiResponse::error('Access denied', 403, 'FORBIDDEN');
        }

        return $auth;
    }

    /**
     * Extract Bearer token from Authorization header
     */
    private function extractBearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        
        if (preg_match('/Bearer\s+(.+)$/i', $header, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Create access and refresh tokens
     */
    private function createTokens(string $userType, int $userId, array $userData): array
    {
        $accessToken = bin2hex(random_bytes(self::TOKEN_LENGTH));
        $refreshToken = bin2hex(random_bytes(self::TOKEN_LENGTH));
        
        $accessExpiry = date('Y-m-d H:i:s', time() + self::ACCESS_TOKEN_EXPIRY);
        $refreshExpiry = date('Y-m-d H:i:s', time() + self::REFRESH_TOKEN_EXPIRY);
        
        $deviceInfo = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 255);
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $stmt = $this->db->prepare(
            "INSERT INTO api_tokens 
             (user_type, user_id, access_token, refresh_token, access_expires_at, refresh_expires_at, 
              device_info, ip_address, user_agent, last_used_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        $stmt->bind_param(
            'sisssssss',
            $userType,
            $userId,
            $accessToken,
            $refreshToken,
            $accessExpiry,
            $refreshExpiry,
            $deviceInfo,
            $ipAddress,
            $userAgent
        );
        $stmt->execute();
        $stmt->close();

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => self::ACCESS_TOKEN_EXPIRY,
            'token_type' => 'Bearer',
            'user' => $userData,
        ];
    }

    /**
     * Get user data by type and ID
     */
    private function getUserData(string $userType, int $userId): ?array
    {
        if ($userType === 'donor') {
            return $this->getDonorById($userId);
        }

        $stmt = $this->db->prepare(
            "SELECT id, name, phone, role FROM users WHERE id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user) {
            return [
                'id' => (int) $user['id'],
                'name' => $user['name'],
                'phone' => $user['phone'],
                'role' => $user['role'],
            ];
        }

        return null;
    }

    /**
     * Get donor by phone
     */
    private function getDonorByPhone(string $phone): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, name, phone, total_pledged, total_paid, balance, 
                    has_active_plan, active_payment_plan_id, payment_status,
                    preferred_payment_method, preferred_language
             FROM donors WHERE phone = ? LIMIT 1"
        );
        $stmt->bind_param('s', $phone);
        $stmt->execute();
        $donor = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($donor) {
            return [
                'id' => (int) $donor['id'],
                'name' => $donor['name'],
                'phone' => $donor['phone'],
                'total_pledged' => (float) $donor['total_pledged'],
                'total_paid' => (float) $donor['total_paid'],
                'balance' => (float) $donor['balance'],
                'has_active_plan' => (bool) $donor['has_active_plan'],
                'payment_status' => $donor['payment_status'],
            ];
        }

        return null;
    }

    /**
     * Get donor by ID
     */
    private function getDonorById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, name, phone, total_pledged, total_paid, balance, 
                    has_active_plan, active_payment_plan_id, payment_status,
                    preferred_payment_method, preferred_language
             FROM donors WHERE id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $donor = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($donor) {
            return [
                'id' => (int) $donor['id'],
                'name' => $donor['name'],
                'phone' => $donor['phone'],
                'total_pledged' => (float) $donor['total_pledged'],
                'total_paid' => (float) $donor['total_paid'],
                'balance' => (float) $donor['balance'],
                'has_active_plan' => (bool) $donor['has_active_plan'],
                'payment_status' => $donor['payment_status'],
            ];
        }

        return null;
    }

    /**
     * Verify donor OTP code
     */
    private function verifyDonorOtp(string $phone, string $code): bool
    {
        $code = preg_replace('/[^0-9]/', '', $code);
        
        $stmt = $this->db->prepare(
            "SELECT id, code, attempts FROM donor_otp_codes 
             WHERE phone = ? AND expires_at > NOW() AND verified = 0
             ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->bind_param('s', $phone);
        $stmt->execute();
        $otp = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$otp) {
            return false;
        }

        // Check max attempts (5)
        if ($otp['attempts'] >= 5) {
            $del = $this->db->prepare("DELETE FROM donor_otp_codes WHERE id = ?");
            $del->bind_param('i', $otp['id']);
            $del->execute();
            $del->close();
            return false;
        }

        if ($otp['code'] !== $code) {
            $inc = $this->db->prepare("UPDATE donor_otp_codes SET attempts = attempts + 1 WHERE id = ?");
            $inc->bind_param('i', $otp['id']);
            $inc->execute();
            $inc->close();
            return false;
        }

        // Mark as verified
        $verify = $this->db->prepare("UPDATE donor_otp_codes SET verified = 1 WHERE id = ?");
        $verify->bind_param('i', $otp['id']);
        $verify->execute();
        $verify->close();

        return true;
    }

    /**
     * Normalize UK phone number
     */
    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);
        
        // Convert +44 to 0
        if (str_starts_with($digits, '44') && strlen($digits) === 12) {
            $digits = '0' . substr($digits, 2);
        }

        return $digits;
    }
}

