<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/url.php';

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$raw = (string)($_GET['c'] ?? '');
$code = preg_replace('/\D+/', '', $raw);
$isValid = (strlen($code) === 6);
$loginUrl = url_for('/registrar/login.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Copy Access Code</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f7f7f9; }
        .copy-card { max-width: 520px; margin: 10vh auto; box-shadow: 0 10px 30px rgba(0,0,0,0.08); }
        .code-box { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; font-size: 2rem; letter-spacing: 0.2rem; }
        .muted { color: #6c757d; }
    </style>
    <script>
        function copyCode() {
            const code = document.getElementById('codeValue').textContent.trim();
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(code).then(() => showCopied(), showFallback);
            } else {
                showFallback();
            }
        }
        function showFallback() {
            const input = document.getElementById('fallbackInput');
            input.value = document.getElementById('codeValue').textContent.trim();
            input.select();
            input.setSelectionRange(0, 99999);
            try { document.execCommand('copy'); showCopied(); } catch (e) { /* ignore */ }
        }
        function showCopied() {
            const btn = document.getElementById('copyBtn');
            const original = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-check me-1"></i>Copied!';
            setTimeout(() => btn.innerHTML = original, 2000);
        }
        document.addEventListener('DOMContentLoaded', () => {
            // Auto-copy on load for faster UX
            <?php if ($isValid): ?>
            copyCode();
            <?php endif; ?>
        });
    </script>
</head>
<body>
<div class="card copy-card">
    <div class="card-body p-4 text-center">
        <h5 class="mb-3">Your Access Code</h5>
        <?php if ($isValid): ?>
            <div id="codeValue" class="code-box fw-bold mb-3"><?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?></div>
            <input id="fallbackInput" type="text" class="visually-hidden" aria-hidden="true">
            <button id="copyBtn" class="btn btn-success btn-lg w-100 mb-3" onclick="copyCode()">
                <i class="fas fa-copy me-1"></i>Copy Code
            </button>
            <a href="<?php echo htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-primary w-100">Go to Login</a>
            <p class="mt-3 mb-0 muted">Tip: The code is copied automatically when this page opens.</p>
        <?php else: ?>
            <div class="alert alert-warning">Invalid or missing code.</div>
            <a href="<?php echo htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-primary w-100">Go to Login</a>
        <?php endif; ?>
    </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
</body>
</html>


