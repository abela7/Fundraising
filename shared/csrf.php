<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_input(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function verify_csrf($exit_on_failure = true): bool {
    $valid = ($_POST['csrf_token'] ?? '') === ($_SESSION['csrf_token'] ?? '');
    if (!$valid && $exit_on_failure) {
        http_response_code(403);
        die('Invalid security token. Please go back, refresh the page, and try again.');
    }
    return $valid;
}

function verify_csrf_for_download(): bool {
    return ($_POST['csrf_token'] ?? '') === ($_SESSION['csrf_token'] ?? '');
}