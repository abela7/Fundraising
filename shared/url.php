<?php
declare(strict_types=1);

/**
 * URL helpers for building links that work under a subfolder (e.g., /Fundraising)
 */

function app_base_path(): string {
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $script = str_replace('\\', '/', $script);
    $markers = ['/admin/', '/registrar/', '/public/', '/api/'];
    foreach ($markers as $marker) {
        $pos = strpos($script, $marker);
        if ($pos !== false) {
            $base = substr($script, 0, $pos);
            return $base === '/' ? '' : rtrim($base, '/');
        }
    }
    $dir = rtrim(dirname($script), '/');
    return $dir === '/' ? '' : $dir;
}

function url_for(string $path): string {
    $base = app_base_path();
    $path = '/' . ltrim($path, '/');
    return $base . $path;
}


