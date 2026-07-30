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

    /** Crisp output on website uses html2canvas scale 2; headless uses 1 to avoid OOM/crash. */
    private const SCALE_FACTOR = 1;

    /** Time budget for fonts / QR / background images to finish loading. */
    private const VIRTUAL_TIME_BUDGET_MS = 20000;

    /** Match review-pledge-payments certificate upload limit. */
    private const UPLOAD_LIMIT_BYTES = 5 * 1024 * 1024;

    private const MAX_OUTPUT_WIDTH = 1200;

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

    public static function unavailableDiagnosticReport(): string
    {
        return "🔎 *Certificate diagnostic*\n"
            . "Stage: Renderer startup\n"
            . 'PHP SAPI: ' . php_sapi_name() . "\n"
            . 'Shell: exec=' . (self::isFunctionDisabled('exec') ? 'off' : 'on')
            . ', proc_open=' . (self::isFunctionDisabled('proc_open') ? 'off' : 'on') . "\n"
            . 'Chrome: ' . (self::chromeBinary() !== null ? 'detected' : 'not found') . "\n"
            . 'Reason: ' . self::unavailableReason();
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

        $attemptProfiles = [
            [
                'scale' => self::SCALE_FACTOR,
                'flags' => [
                    '--no-zygote',
                    '--disable-setuid-sandbox',
                    '--disable-breakpad',
                    '--disable-software-rasterizer',
                ],
            ],
            [
                'scale' => self::SCALE_FACTOR,
                'flags' => [
                    '--single-process',
                    '--no-zygote',
                    '--disable-setuid-sandbox',
                    '--disable-breakpad',
                ],
            ],
        ];

        $attempts = [];
        foreach ($attemptProfiles as $index => $profile) {
            $stderrFile = sys_get_temp_dir() . '/cert-chrome-' . uniqid('', true) . '.log';
            $attempt = $this->runScreenshotAttempt(
                $binary,
                $url,
                $type,
                $width,
                $height,
                $outputPath,
                $stderrFile,
                (int)$profile['scale'],
                $profile['flags']
            );
            $stderr = is_file($stderrFile)
                ? self::sanitizeDiagnosticText((string)@file_get_contents($stderrFile))
                : '';
            @unlink($stderrFile);
            $attempts[] = [
                'number' => $index + 1,
                'exit_code' => (int)($attempt['exit_code'] ?? 1),
                'file_bytes' => (int)($attempt['file_bytes'] ?? 0),
                'stderr' => $stderr,
            ];
            if (!empty($attempt['success'])) {
                $this->optimizeOutputImage($outputPath);
                return ['success' => true, 'path' => $outputPath];
            }
        }

        return [
            'success' => false,
            'error' => self::buildFailureReport($binary, $url, $type, $attempts),
        ];
    }

    /**
     * @param list<string> $extraFlags
     * @return array{success:bool,error?:string,exit_code?:int,file_bytes?:int}
     */
    private function runScreenshotAttempt(
        string $binary,
        string $url,
        string $type,
        int $width,
        int $height,
        string $outputPath,
        string $stderrFile,
        int $scaleFactor,
        array $extraFlags
    ): array {
        @unlink($outputPath);

        $profileDir = self::freshProfileDir();
        $args = array_merge([
            '--headless',
            '--disable-gpu',
            '--no-sandbox',
            '--disable-dev-shm-usage',
            '--hide-scrollbars',
            '--force-device-scale-factor=' . max(1, $scaleFactor),
            '--window-size=' . $width . ',' . $height,
            '--virtual-time-budget=' . self::VIRTUAL_TIME_BUDGET_MS,
            '--run-all-compositor-stages-before-draw',
            '--disable-extensions',
            '--no-first-run',
            // Unique profile per attempt: a stale SingletonLock in a reused
            // profile dir makes Chrome crash with exit 133 on shared hosting.
            '--user-data-dir=' . $profileDir,
            '--screenshot=' . $outputPath,
        ], $extraFlags, [$url]);

        $cmd = escapeshellarg($binary);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }

        $envPrefix = self::buildExecEnvPrefix($binary, $profileDir);
        if ($envPrefix !== '') {
            $cmd = $envPrefix . $cmd;
        }

        if (DIRECTORY_SEPARATOR !== '\\') {
            $cmd .= ' 2>' . escapeshellarg($stderrFile);
        }

        $output = [];
        $exitCode = 1;
        self::runShellCommand($cmd, $output, $exitCode);
        self::removeProfileDir($profileDir);

        $fileBytes = is_file($outputPath) ? (int)filesize($outputPath) : 0;
        if ($exitCode !== 0 || $fileBytes < 1024) {
            @unlink($outputPath);
            return [
                'success' => false,
                'error' => 'Headless Chrome screenshot failed (exit ' . $exitCode
                    . ($fileBytes > 0 ? ', ' . $fileBytes . ' bytes' : '') . ')',
                'exit_code' => $exitCode,
                'file_bytes' => $fileBytes,
            ];
        }

        return ['success' => true, 'exit_code' => $exitCode, 'file_bytes' => $fileBytes];
    }

    /**
     * @param list<array{number:int,exit_code:int,file_bytes:int,stderr:string}> $attempts
     */
    private static function buildFailureReport(
        string $binary,
        string $url,
        string $type,
        array $attempts
    ): string {
        $targetPath = (string)(parse_url($url, PHP_URL_PATH) ?: 'unknown');
        $lines = [
            '🔎 *Certificate diagnostic*',
            'Stage: Headless Chrome screenshot',
            'Target: ' . $targetPath . ' (' . $type . ')',
            'PHP SAPI: ' . php_sapi_name(),
            'Chrome: ' . basename($binary),
            'Shell: exec=' . (self::isFunctionDisabled('exec') ? 'off' : 'on')
                . ', proc_open=' . (self::isFunctionDisabled('proc_open') ? 'off' : 'on'),
        ];

        foreach ($attempts as $attempt) {
            $lines[] = 'Attempt ' . $attempt['number'] . ': exit '
                . $attempt['exit_code'] . ', output ' . $attempt['file_bytes'] . ' bytes';
            if ($attempt['stderr'] !== '') {
                $lines[] = 'Chrome: ' . $attempt['stderr'];
            }
        }

        return implode("\n", $lines);
    }

    private static function sanitizeDiagnosticText(string $value): string
    {
        $value = preg_replace('/(view_token|token)=[^&\s]+/i', '$1=[redacted]', $value) ?? '';
        $value = preg_replace(
            '#/(?:home|opt|snap|proc|run|srv|etc|var|tmp|usr|app|web)[^\s:]*#',
            '[server-path]',
            $value
        ) ?? '';
        $value = preg_replace('#https?://[^\s]+#i', '[url-redacted]', $value) ?? '';
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';
        return mb_substr($value, 0, 700);
    }

    /**
     * Resize/compress like review-pledge-payments optimizeCertificateBlob.
     */
    private function optimizeOutputImage(string $outputPath): void
    {
        if (!function_exists('imagecreatefrompng') || !is_file($outputPath)) {
            return;
        }

        $info = @getimagesize($outputPath);
        if ($info === false) {
            return;
        }

        $width = (int)($info[0] ?? 0);
        $height = (int)($info[1] ?? 0);
        if ($width <= 0 || $height <= 0) {
            return;
        }

        $image = @imagecreatefrompng($outputPath);
        if ($image === false) {
            return;
        }

        $targetWidth = $width;
        if ($targetWidth > self::MAX_OUTPUT_WIDTH) {
            $targetWidth = self::MAX_OUTPUT_WIDTH;
        }
        $targetHeight = (int)round($height * ($targetWidth / $width));

        $canvas = $image;
        if ($targetWidth !== $width) {
            $resized = imagecreatetruecolor($targetWidth, $targetHeight);
            if ($resized !== false) {
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                imagecopyresampled($resized, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
                imagedestroy($image);
                $canvas = $resized;
            }
        }

        $pngOk = imagepng($canvas, $outputPath, 6);
        $size = $pngOk ? (int)filesize($outputPath) : PHP_INT_MAX;
        if ($size <= self::UPLOAD_LIMIT_BYTES) {
            imagedestroy($canvas);
            return;
        }

        $jpegPath = preg_replace('/\.png$/i', '.jpg', $outputPath) ?? ($outputPath . '.jpg');
        foreach ([92, 86, 80, 74] as $quality) {
            if (!imagejpeg($canvas, $jpegPath, $quality)) {
                continue;
            }
            if ((int)filesize($jpegPath) <= self::UPLOAD_LIMIT_BYTES) {
                @unlink($outputPath);
                if ($jpegPath !== $outputPath) {
                    @rename($jpegPath, $outputPath);
                }
                imagedestroy($canvas);
                return;
            }
        }

        imagedestroy($canvas);
        @unlink($jpegPath);
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
     * Give Chrome a private writable runtime environment under PHP-FPM.
     */
    private static function buildExecEnvPrefix(string $chromeBinary, string $profileDir): string
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return '';
        }

        $assignments = [];
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
        if (!empty($parts)) {
            $assignments[] = 'LD_LIBRARY_PATH=' . escapeshellarg(implode(':', $parts));
        }

        $runtimeDir = $profileDir . DIRECTORY_SEPARATOR . 'runtime';
        $cacheDir = $profileDir . DIRECTORY_SEPARATOR . 'cache';

        $home = self::resolveHomeDir();
        if ($home !== '') {
            $assignments[] = 'HOME=' . escapeshellarg($home);
        }
        $assignments[] = 'XDG_CONFIG_HOME=' . escapeshellarg($profileDir);
        if (is_dir($cacheDir) || @mkdir($cacheDir, 0700, true)) {
            $assignments[] = 'XDG_CACHE_HOME=' . escapeshellarg($cacheDir);
        }
        if (is_dir($runtimeDir) || @mkdir($runtimeDir, 0700, true)) {
            $assignments[] = 'XDG_RUNTIME_DIR=' . escapeshellarg($runtimeDir);
        }

        return implode(' ', $assignments) . ' ';
    }

    private static function tempProfileDir(): string
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cert-chrome-profile';
        if (!is_dir($base)) {
            @mkdir($base, 0755, true);
        }
        return $base;
    }

    private static function freshProfileDir(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cert-chrome-profile-' . uniqid('', true);
        @mkdir($dir, 0755, true);
        return $dir;
    }

    private static function removeProfileDir(string $dir): void
    {
        if ($dir === '' || !is_dir($dir) || basename($dir) === 'cert-chrome-profile') {
            return;
        }
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $file) {
                if ($file->isDir()) {
                    @rmdir($file->getPathname());
                } else {
                    @unlink($file->getPathname());
                }
            }
            @rmdir($dir);
        } catch (Throwable $ignored) {
        }
    }
}
