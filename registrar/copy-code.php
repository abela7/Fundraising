<?php
declare(strict_types=1);
require_once __DIR__ . '/../shared/url.php';

$code = trim((string)($_GET['c'] ?? ''));
// Only allow 6-digit numeric codes
if (!preg_match('/^\d{6}$/', $code)) {
    http_response_code(400);
    $code = '';
}
$loginUrl = url_for('/registrar/login.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Copy Access Code</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { display:flex; align-items:center; justify-content:center; min-height:100vh; background:#f7f9fc; }
        .card { max-width:420px; width:100%; box-shadow: 0 10px 30px rgba(0,0,0,0.08); }
        .code-box { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; font-size:1.6rem; letter-spacing:0.2rem; }
    </style>
    <script>
        function copyCode() {
            const el = document.getElementById('code');
            const code = el.innerText.trim();
            navigator.clipboard?.writeText(code).then(() => {
                showCopied();
            }).catch(() => {
                const ta = document.createElement('textarea');
                ta.value = code; document.body.appendChild(ta); ta.select();
                try { document.execCommand('copy'); showCopied(); } finally { document.body.removeChild(ta); }
            });
        }
        function showCopied() {
            const b = document.getElementById('copyBtn');
            const old = b.innerHTML;
            b.innerHTML = '<i class="fas fa-check me-2"></i>Copied';
            setTimeout(()=> b.innerHTML = old, 1800);
        }
        document.addEventListener('DOMContentLoaded', () => {
            const hasAuto = sessionStorage.getItem('autoCopied');
            if (!hasAuto) {
                copyCode();
                sessionStorage.setItem('autoCopied', '1');
            }
        });
    </script>
</head>
<body>
    <div class="card p-4">
        <h5 class="mb-3">Your Access Code</h5>
        <div class="alert alert-info d-flex align-items-center justify-content-between">
            <div id="code" class="code-box"><?php echo htmlspecialchars($code ?: '------'); ?></div>
            <button id="copyBtn" class="btn btn-primary btn-sm" onclick="copyCode()">Copy</button>
        </div>
        <a class="btn btn-success w-100" href="<?php echo htmlspecialchars($loginUrl); ?>">Go to Login</a>
        <p class="text-muted mt-3 mb-0" style="font-size:0.9rem;">Tip: If copy didn’t work, tap the code above to select and copy.</p>
    </div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
</body>
</html>


