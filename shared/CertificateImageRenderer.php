<?php
/**
 * Server-side certificate image renderer.
 *
 * Uses headless Chrome to screenshot cert/render.php so the WhatsApp
 * PAY flow can send the exact same certificate the website produces
 * with html2canvas — without needing a browser.
 *
 * Chrome location resolution order:
 *   1. CERT_CHROME_PATH constant (define in config/env.php on the server)
 *   2. CERT_CHROME_PATH environment variable
 *   3. Common install paths (Windows Chrome/Edge, Linux chrome/chromium)
 *
 * Shared Linux hosting: place user-space libs under
 * ~/chrome/libs/usr/lib/x86_64-linux-gnu (or CERT_CHROME_LIB_PATH).
 *
 * If Chrome is not found the renderer reports unavailable and callers
 * must gracefully fall back to text-only confirmations.
 */

declare(strict_types=1);

class CertificateImageRenderer
{
    private const VIEWPORT = [
        'progress' => [1200, 970],
        'completed' => [1200, 850],
    ];

    /** Crisp output like html2canvas scale: 2 on the website. */
    private const SCALE_FACTOR = 2;

    /** Time budget for fonts / QR / background images to finish loading. */
    private const VIRTUAL_TIME_BUDGET_MS = 15000;

    private static ?string $resolvedBinary = null;

    /**
     * Absolute path to the Chrome/Chromium binary, or null if unavailable.
     */
    public static function chromeBinary(): ?string
    {
        if (self::$resolvedBinary !== null) {
            return self::$resolvedBinary !== '' ? self::$resolvedBinary : null;
        }

        $candidates = [];

        if (defined('CERT_CHROME_PATH') && CERT_CHROME_PATH !== '') {
            $candidates[] = (string)CERT_CHROME_PATH;
        }
        $env = getenv('CERT_CHROME_PATH');
        if (is_string($env) && $env !== '') {
            $candidates[] = $env;
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            $candidates = array_merge($candidates, [
                'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
                'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
                'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
                'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
            ]);
        } else {
            $home = self::resolveHomeDir();
            $candidates = array_merge($candidates, [
                // User-space Chrome for Testing installs (shared cPanel hosting)
                $home . '/chrome/chrome-headless-shell-linux64/chrome-headless-shell',
                $home . '/chrome-headless-shell-linux64/chrome-headless-shell',
                $home . '/chrome/chrome-linux64/chrome',
                // System-wide installs
                '/usr/bin/google-chrome',
                '/usr/bin/google-chrome-stable',
                '/usr/bin/chromium',
                '/usr/bin/chromium-browser',
                '/snap/bin/chromium',
                '/opt/google/chrome/chrome',
            ]);
        }

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && @is_file($candidate)) {
                self::$resolvedBinary = $candidate;
                return $candidate;
            }
        }

        // Last resort: ask the OS PATH.
        $which = self::findOnPath(self::findOnPathNames());
        self::$resolvedBinary = $which ?? '';

        return self::$resolvedBinary !== '' ? self::$resolvedBinary : null;
    }

    public static function isAvailable(): bool
    {
        return self::chromeBinary() !== null && self::canRunShellCommands();
    }

    /**
     * Human-readable reason when isAvailable() is false (for operator-facing errors).
     */
    public static function unavailableReason(): string
    {
        if (!self::canRunShellCommands()) {
            return 'PHP cannot run shell commands (exec/proc_open disabled)';
        }
        if (self::chromeBinary() === null) {
            return 'Chrome/Chromium binary not found (set CERT_CHROME_PATH in config/env.local.php)';
        }

        return '';
    }

    public static function canRunShellCommands(): bool
    {
        if (!self::isFunctionDisabled('exec')) {
            return true;
        }

        return !self::isFunctionDisabled('proc_open');
    }

    /**
     * Screenshot the given URL at the laptop viewport for the cert type.
     *
     * @return array{success:bool, path?:string, error?:string}
     */
    public function render(string $url, string $type, string $outputPath): array
    {
        $binary = self::chromeBinary();
        if ($binary === null) {
            return ['success' => false, 'error' => 'Chrome/Chromium binary not found on server'];
        }
        if (!in_array($type, ['progress', 'completed'], true)) {
            return ['success' => false, 'error' => 'Unknown certificate type: ' . $type];
        }

        [$width, $height] = self::VIEWPORT[$type];

        $dir = dirname($outputPath);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return ['success' => false, 'error' => 'Cannot create output directory'];
        }

        $args = [
            // Plain --headless works on both chrome-headless-shell and
            // current full Chrome (where old/new headless were unified).
            '--headless',
            '--disable-gpu',
            '--no-sandbox',
            '--disable-dev-shm-usage',
            '--hide-scrollbars',
            '--force-device-scale-factor=' . self::SCALE_FACTOR,
            '--window-size=' . $width . ',' . $height,
            '--virtual-time-budget=' . self::VIRTUAL_TIME_BUDGET_MS,
            '--run-all-compositor-stages-before-draw',
            '--disable-extensions',
            '--no-first-run',
            '--user-data-dir=' . self::tempProfileDir(),
            '--screenshot=' . $outputPath,
            $url,
        ];

        $cmd = escapeshellarg($binary);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }

        $envPrefix = self::buildExecEnvPrefix($binary);
        if ($envPrefix !== '') {
            $cmd = $envPrefix . $cmd;
        }

        if (DIRECTORY_SEPARATOR !== '\\') {
            // exec() only captures stdout; silence Chrome's stderr noise.
            $cmd .= ' 2>/dev/null';
        }

        $output = [];
        $exitCode = 1;
        self::runShellCommand($cmd, $output, $exitCode);

        if ($exitCode !== 0 || !is_file($outputPath) || filesize($outputPath) < 1024) {
            $size = is_file($outputPath) ? (int)filesize($outputPath) : 0;
            @unlink($outputPath);
            return [
                'success' => false,
                'error' => 'Headless Chrome screenshot failed (exit ' . $exitCode
                    . ($size > 0 ? ', ' . $size . ' bytes' : '')
                    . '). Check cert URL is reachable: ' . $url,
            ];
        }

        return ['success' => true, 'path' => $outputPath];
    }

    /**
     * @param list<string> $names
     */
    private static function findOnPath(array $names): ?string
    {
        foreach ($names as $name) {
            $isWin = DIRECTORY_SEPARATOR === '\\';
            $cmd = $isWin ? 'where ' . escapeshellarg($name) : 'command -v ' . escapeshellarg($name);
            $out = [];
            $code = 1;
            self::runShellCommand($cmd, $out, $code);
            if ($code === 0 && !empty($out[0])) {
                $path = trim((string)$out[0]);
                if ($path !== '') {
                    return $path;
                }
            }
        }
        return null;
    }

    private static function findOnPathNames(): array
    {
        return DIRECTORY_SEPARATOR === '\\'
            ? ['chrome.exe', 'msedge.exe']
            : ['google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser', 'chrome-headless-shell'];
    }

    private static function resolveHomeDir(): string
    {
        $home = (string)(getenv('HOME') ?: '');
        if ($home !== '' && is_dir($home)) {
            return $home;
        }
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = posix_getpwuid(posix_geteuid());
            if (is_array($info) && !empty($info['dir']) && is_dir((string)$info['dir'])) {
                return (string)$info['dir'];
            }
        }

        // cPanel: app often lives at /home/user/domain.tld (HOME unset under PHP-FPM).
        $appRoot = dirname(__DIR__);
        if (preg_match('#^/home/([^/]+)(/|$)#', $appRoot, $matches) === 1) {
            $candidate = '/home/' . $matches[1];
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * @param list<string> $output
     */
    private static function runShellCommand(string $cmd, array &$output, int &$exitCode): void
    {
        $output = [];
        $exitCode = 1;

        if (!self::isFunctionDisabled('exec')) {
            @exec($cmd, $output, $exitCode);
            return;
        }

        if (self::isFunctionDisabled('proc_open')) {
            return;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            return;
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if (is_string($stdout) && $stdout !== '') {
            $output = preg_split('/\r\n|\r|\n/', trim($stdout)) ?: [];
        }
    }

    private static function isFunctionDisabled(string $function): bool
    {
        if (!function_exists($function)) {
            return true;
        }
        $disabled = ini_get('disable_functions');
        if (!is_string($disabled) || $disabled === '') {
            return false;
        }

        return in_array($function, array_map('trim', explode(',', $disabled)), true);
    }

    private static function chromeLibraryPath(): string
    {
        if (defined('CERT_CHROME_LIB_PATH') && CERT_CHROME_LIB_PATH !== '') {
            return (string)CERT_CHROME_LIB_PATH;
        }
        $env = getenv('CERT_CHROME_LIB_PATH');
        if (is_string($env) && $env !== '' && is_dir($env)) {
            return $env;
        }
        $home = self::resolveHomeDir();
        if ($home === '') {
            return '';
        }
        $default = $home . '/chrome/libs/usr/lib/x86_64-linux-gnu';
        return is_dir($default) ? $default : '';
    }

    /**
     * Prefix exec with LD_LIBRARY_PATH when bundled libs exist (Linux only).
     */
    private static function buildExecEnvPrefix(string $chromeBinary): string
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return '';
        }

        $parts = [];
        $libPath = self::chromeLibraryPath();
        if ($libPath !== '') {
            $parts[] = $libPath;
        }
        $chromeDir = dirname($chromeBinary);
        if ($chromeDir !== '' && is_dir($chromeDir)) {
            $parts[] = $chromeDir;
        }
        $existing = getenv('LD_LIBRARY_PATH');
        if (is_string($existing) && $existing !== '') {
            $parts[] = $existing;
        }
        if (empty($parts)) {
            return '';
        }

        return 'LD_LIBRARY_PATH=' . escapeshellarg(implode(':', $parts)) . ' ';
    }

    private static function tempProfileDir(): string
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cert-chrome-profile';
        if (!is_dir($base)) {
            @mkdir($base, 0755, true);
        }
        return $base;
    }
}
