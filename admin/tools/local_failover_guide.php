<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_admin();

// Detect likely LAN IPs for sharing
function detect_candidate_ips(): array {
    $candidates = [];
    $serverAddr = $_SERVER['SERVER_ADDR'] ?? '';
    if ($serverAddr && $serverAddr !== '127.0.0.1') { $candidates[] = $serverAddr; }
    $httpHost = $_SERVER['HTTP_HOST'] ?? '';
    if ($httpHost && filter_var(preg_replace('/:.*/', '', $httpHost), FILTER_VALIDATE_IP)) {
        $candidates[] = preg_replace('/:.*/', '', $httpHost);
    }
    $hostIp = gethostbyname(gethostname());
    if ($hostIp && $hostIp !== '127.0.0.1') { $candidates[] = $hostIp; }
    // Deduplicate and keep IPv4-like items
    $candidates = array_values(array_unique(array_filter($candidates, function($ip){
        return (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
    })));
    // Fallback typical hotspot subnets to guide the user
    if (empty($candidates)) {
        $candidates = ['192.168.43.x', '172.20.10.x'];
    }
    return $candidates;
}

// Build app base path from current request (e.g., /Fundraising)
$reqPath = $_SERVER['REQUEST_URI'] ?? '/';
$appPath = dirname(dirname(dirname($reqPath))); // from /Fundraising/admin/tools/... -> /Fundraising
if ($appPath === DIRECTORY_SEPARATOR || $appPath === '.') { $appPath = '/'; }

$ips = detect_candidate_ips();
$primaryIp = $ips[0] ?? 'YOUR-IP-HERE';
$preferLaptopHotspot = true; // Force Windows Mobile Hotspot default
if ($preferLaptopHotspot) {
    $primaryIp = '192.168.137.1';
}
$scheme = 'http';

// Build shareable URLs using the primary IP
$registrarUrl = rtrim($scheme . '://' . $primaryIp . rtrim($appPath, '/') . '/registrar/', '/').'/';
$projectorUrl = rtrim($scheme . '://' . $primaryIp . rtrim($appPath, '/') . '/public/projector/', '/').'/';

$page_title = 'Local Failover Guide & Share Links';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
</head>
<body>
<div class="container mt-4 mb-4">
    <div class="row">
        <div class="col-lg-10 mx-auto">
            <div class="card shadow-sm">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                    <h4 class="mb-0"><i class="fas fa-plug me-2"></i>Local Failover Guide</h4>
                    <span class="badge bg-secondary">Env: <?php echo strtoupper(defined('ENVIRONMENT')?ENVIRONMENT:'unknown'); ?></span>
                </div>
                <div class="card-body">

                    <div class="alert alert-info">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <strong>Detected IP candidates:</strong>
                                <?php foreach ($ips as $ip): ?>
                                    <span class="badge bg-primary me-1"><?php echo htmlspecialchars($ip); ?></span>
                                <?php endforeach; ?>
                                <div class="small text-muted mt-1">
                                    Using Windows Mobile Hotspot. Links below are fixed to <strong>http://192.168.137.1</strong> for fastest failover.
                                </div>
                            </div>
                            <button class="btn btn-outline-primary btn-sm" onclick="location.reload()"><i class="fas fa-rotate"></i> Refresh</button>
                        </div>
                    </div>

                    <div class="row g-3 align-items-stretch">
                        <div class="col-md-6">
                            <div class="card h-100 border-success">
                                <div class="card-header bg-success text-white">
                                    <i class="fas fa-user-check me-2"></i>Registrar Link
                                </div>
                                <div class="card-body">
                                    <div class="input-group mb-2">
                                        <input id="registrarUrl" type="text" class="form-control" value="<?php echo htmlspecialchars($registrarUrl); ?>" readonly>
                                        <button class="btn btn-outline-secondary" onclick="copyToClipboard('registrarUrl')"><i class="fas fa-copy"></i></button>
                                        <a class="btn btn-primary" target="_blank" href="<?php echo htmlspecialchars($registrarUrl); ?>"><i class="fas fa-up-right-from-square"></i></a>
                                    </div>
                                    <div class="text-center">
                                        <canvas id="qr-registrar" width="240" height="240" style="display:none"></canvas>
                                        <img id="qr-registrar-fallback" alt="QR Code" style="display:none;width:240px;height:240px;border:1px solid #eee;border-radius:6px;"/>
                                        <div class="small text-muted mt-2">Share this QR with registrars to open the local form.</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card h-100 border-info">
                                <div class="card-header bg-info text-white">
                                    <i class="fas fa-tv me-2"></i>Projector Link
                                </div>
                                <div class="card-body">
                                    <div class="input-group mb-2">
                                        <input id="projectorUrl" type="text" class="form-control" value="<?php echo htmlspecialchars($projectorUrl); ?>" readonly>
                                        <button class="btn btn-outline-secondary" onclick="copyToClipboard('projectorUrl')"><i class="fas fa-copy"></i></button>
                                        <a class="btn btn-primary" target="_blank" href="<?php echo htmlspecialchars($projectorUrl); ?>"><i class="fas fa-up-right-from-square"></i></a>
                                    </div>
                                    <div class="text-center">
                                        <canvas id="qr-projector" width="240" height="240" style="display:none"></canvas>
                                        <img id="qr-projector-fallback" alt="QR Code" style="display:none;width:240px;height:240px;border:1px solid #eee;border-radius:6px;"/>
                                        <div class="small text-muted mt-2">Scan on projector device if needed.</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="accordion mt-4" id="failoverAccordion">
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="headingOne">
                                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOne" aria-expanded="true" aria-controls="collapseOne">
                                    1) Use Computer (Windows) Mobile Hotspot (Recommended)
                                </button>
                            </h2>
                            <div id="collapseOne" class="accordion-collapse collapse show" aria-labelledby="headingOne" data-bs-parent="#failoverAccordion">
                                <div class="accordion-body">
                                    <ol class="mb-0">
                                        <li>Open Windows Settings → Network & Internet → Mobile hotspot → Turn it ON.</li>
                                        <li>Set a friendly hotspot name and password. Keep the Settings window open (prevents sleep).</li>
                                        <li>Ensure XAMPP Apache and MySQL are running (green).</li>
                                        <li>Open this page again; confirm the IP list shows a private IP (e.g., 192.168.x.x).</li>
                                        <li>Share the Registrar link or QR above with your team.</li>
                                        <li>Test on one phone: open the registrar link and submit a small test. The projector should shade.</li>
                                        <li>If phones cannot connect, allow Apache through Windows Firewall (Private network).</li>
                                    </ol>
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="headingTwo">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTwo" aria-expanded="false" aria-controls="collapseTwo">
                                    2) Use Another Phone as Hotspot
                                </button>
                            </h2>
                            <div id="collapseTwo" class="accordion-collapse collapse" aria-labelledby="headingTwo" data-bs-parent="#failoverAccordion">
                                <div class="accordion-body">
                                    <ol class="mb-0">
                                        <li>Turn on hotspot on a central phone. Plug it into power and disable battery saver.</li>
                                        <li>Connect your laptop to that hotspot. Keep XAMPP Apache/MySQL running.</li>
                                        <li>Reload this page to detect the laptop IP on that hotspot.</li>
                                        <li>Share the Registrar link/QR above to the WhatsApp group.</li>
                                        <li>Test with one registrar phone. If projector doesn’t shade, hard refresh the projector page.</li>
                                        <li>If phones still can’t reach the laptop, some hotspots isolate clients. Switch to Windows Mobile Hotspot.</li>
                                    </ol>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-warning mt-4">
                        <strong>Troubleshooting:</strong>
                        <ul class="mb-0">
                            <li>If pages load but projector doesn’t shade, press Ctrl+F5 on the projector page.</li>
                            <li>Firewall: Allow "Apache HTTP Server" on Private networks in Windows Firewall.</li>
                            <li>URL format: http://YOUR-LAPTOP-IP<?php echo htmlspecialchars(rtrim($appPath, '/')); ?>/registrar/</li>
                        </ul>
                    </div>

                    <div class="mt-3 text-center">
                        <a href="../tools/" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i>Back to Tools</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function copyToClipboard(id) {
    const el = document.getElementById(id);
    if (!el) return;
    navigator.clipboard.writeText(el.value || el.textContent || '').then(() => {
        el.classList.add('is-valid');
        setTimeout(()=>el.classList.remove('is-valid'), 800);
    }).catch(()=>{
        alert('Copy failed. Please copy manually.');
    });
}

// Attempt QR generation: try JS library via CDN, else fallback to remote QR API; else show instructions
(function initQR(){
  const registrarUrl = document.getElementById('registrarUrl').value;
  const projectorUrl = document.getElementById('projectorUrl').value;

  function drawFallback(imgEl, data){
    // Remote QR API fallback (requires internet)
    const url = 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=' + encodeURIComponent(data);
    imgEl.src = url;
    imgEl.style.display = '';
  }

  function tryCdn() {
    const script = document.createElement('script');
    script.src = 'https://cdn.jsdelivr.net/npm/qrcode@1.5.1/build/qrcode.min.js';
    script.async = true;
    script.onload = function(){
      try {
        const regCanvas = document.getElementById('qr-registrar');
        const proCanvas = document.getElementById('qr-projector');
        regCanvas.style.display = '';
        proCanvas.style.display = '';
        window.QRCode.toCanvas(regCanvas, registrarUrl, { width: 240, margin: 2 }, function (error) {
          if (error) {
            regCanvas.style.display = 'none';
            drawFallback(document.getElementById('qr-registrar-fallback'), registrarUrl);
          }
        });
        window.QRCode.toCanvas(proCanvas, projectorUrl, { width: 240, margin: 2 }, function (error) {
          if (error) {
            proCanvas.style.display = 'none';
            drawFallback(document.getElementById('qr-projector-fallback'), projectorUrl);
          }
        });
      } catch (e) {
        drawFallback(document.getElementById('qr-registrar-fallback'), registrarUrl);
        drawFallback(document.getElementById('qr-projector-fallback'), projectorUrl);
      }
    };
    script.onerror = function(){
      drawFallback(document.getElementById('qr-registrar-fallback'), registrarUrl);
      drawFallback(document.getElementById('qr-projector-fallback'), projectorUrl);
    };
    document.head.appendChild(script);
  }

  tryCdn();
})();
</script>
</body>
</html>


