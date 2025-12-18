<?php
declare(strict_types=1);

/**
 * API Response Helper
 * 
 * Standardized JSON response format for all API endpoints.
 */
class ApiResponse
{
    /**
     * Send a success response
     *
     * @param mixed $data Response data
     * @param string|null $message Optional success message
     * @param int $code HTTP status code (default 200)
     */
    public static function success(mixed $data = null, ?string $message = null, int $code = 200): never
    {
        self::send([
            'success' => true,
            'data' => $data,
            'message' => $message,
        ], $code);
    }

    /**
     * Send an error response
     *
     * @param string $message Error message
     * @param int $code HTTP status code
     * @param string|null $errorCode Machine-readable error code
     * @param array|null $errors Detailed validation errors
     */
    public static function error(
        string $message,
        int $code = 400,
        ?string $errorCode = null,
        ?array $errors = null
    ): never {
        $response = [
            'success' => false,
            'error' => [
                'message' => $message,
                'code' => $errorCode ?? self::httpCodeToErrorCode($code),
            ],
        ];

        if ($errors !== null) {
            $response['error']['details'] = $errors;
        }

        self::send($response, $code);
    }

    /**
     * Send a paginated response
     *
     * @param array $items List of items
     * @param int $page Current page (1-based)
     * @param int $perPage Items per page
     * @param int $total Total number of items
     */
    public static function paginated(array $items, int $page, int $perPage, int $total): never
    {
        $totalPages = (int) ceil($total / $perPage);

        self::send([
            'success' => true,
            'data' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
                'has_more' => $page < $totalPages,
            ],
        ], 200);
    }

    /**
     * Send the JSON response
     */
    private static function send(array $data, int $code): never
    {
        // Set headers
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, no-cache, must-revalidate');

        // Allow CORS for PWA
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowedOrigins = self::getAllowedOrigins();
        
        if (in_array($origin, $allowedOrigins, true) || in_array('*', $allowedOrigins, true)) {
            header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Max-Age: 86400');
        }

        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Handle CORS preflight request
     */
    public static function handlePreflight(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
            $allowedOrigins = self::getAllowedOrigins();
            
            if (in_array($origin, $allowedOrigins, true) || in_array('*', $allowedOrigins, true)) {
                header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
                header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
                header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
                header('Access-Control-Allow-Credentials: true');
                header('Access-Control-Max-Age: 86400');
            }
            
            http_response_code(204);
            exit;
        }
    }

    /**
     * Get allowed CORS origins
     */
    private static function getAllowedOrigins(): array
    {
        // In production, this should be configured via environment
        // For now, allow same-origin and localhost for development
        $serverHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        
        return [
            "{$protocol}://{$serverHost}",
            'http://localhost',
            'http://localhost:8080',
            'http://127.0.0.1',
            'https://localhost',
        ];
    }

    /**
     * Map HTTP code to error code string
     */
    private static function httpCodeToErrorCode(int $code): string
    {
        return match ($code) {
            400 => 'BAD_REQUEST',
            401 => 'UNAUTHORIZED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            405 => 'METHOD_NOT_ALLOWED',
            409 => 'CONFLICT',
            422 => 'VALIDATION_ERROR',
            429 => 'RATE_LIMITED',
            500 => 'SERVER_ERROR',
            503 => 'SERVICE_UNAVAILABLE',
            default => 'ERROR',
        };
    }
}

