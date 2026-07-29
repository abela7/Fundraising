<?php
/**
 * Shared HMAC token for the login-free certificate render page.
 *
 * The secret derives from server DB credentials (never in the repo),
 * and the token is scoped to (donor_id, type, day) so render links
 * cannot be reused for other donors or days.
 */

declare(strict_types=1);

if (!function_exists('cert_render_token')) {
    function cert_render_token(int $donorId, string $type, ?string $day = null): string
    {
        $day = $day ?? date('Y-m-d');
        $secret = (defined('DB_PASS') ? (string)DB_PASS : '') . '|cert-render-v1';
        return hash_hmac('sha256', $donorId . '|' . $type . '|' . $day, $secret);
    }
}
