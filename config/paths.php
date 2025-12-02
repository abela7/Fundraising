<?php
declare(strict_types=1);

/**
 * URL Path Obfuscation Configuration
 * 
 * This file defines secret URL segments that map to actual folder names.
 * Change these values to customize your obscured URLs.
 * 
 * SECURITY NOTE: 
 * - Keep these values secret
 * - Use random, non-dictionary strings
 * - Don't commit sensitive values to public repos
 * 
 * HOW IT WORKS:
 * - Users access: /m8k3x9p2/login.php
 * - Server internally serves: /admin/login.php
 * - Direct access to /admin/ returns 404
 * 
 * TO UNDO:
 * - Delete the .htaccess file in the root folder
 * - Everything reverts to normal immediately
 */

// Secret URL segments (change these to your own random strings)
define('PATH_ADMIN_PUBLIC', 'm8k3x9p2');      // Maps to /admin/
define('PATH_REGISTRAR_PUBLIC', 'r4j7n1w5');  // Maps to /registrar/

// Actual folder names (don't change these)
define('PATH_ADMIN_REAL', 'admin');
define('PATH_REGISTRAR_REAL', 'registrar');

/**
 * Get the public (obscured) URL for a given internal path
 * 
 * @param string $internalPath e.g., 'admin/login.php' or 'registrar/index.php'
 * @return string The obscured path e.g., 'm8k3x9p2/login.php'
 */
function get_public_path(string $internalPath): string
{
    $path = ltrim($internalPath, '/');
    
    // Replace admin/ with obscured path
    if (strpos($path, PATH_ADMIN_REAL . '/') === 0) {
        return PATH_ADMIN_PUBLIC . substr($path, strlen(PATH_ADMIN_REAL));
    }
    
    // Replace registrar/ with obscured path
    if (strpos($path, PATH_REGISTRAR_REAL . '/') === 0) {
        return PATH_REGISTRAR_PUBLIC . substr($path, strlen(PATH_REGISTRAR_REAL));
    }
    
    // No change needed
    return $path;
}

/**
 * Check if current request is using the obscured URL
 * Useful for ensuring users are on the correct URL
 */
function is_using_obscured_url(): bool
{
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    return strpos($uri, '/' . PATH_ADMIN_PUBLIC . '/') !== false 
        || strpos($uri, '/' . PATH_REGISTRAR_PUBLIC . '/') !== false;
}

