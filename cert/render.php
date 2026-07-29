<?php
/**
 * Certificate Render Page (headless-Chrome target)
 *
 * Renders EXACTLY the same certificate markup as
 * admin/donor-management/view-donor.php, at a fixed laptop width,
 * without requiring admin login. Access is protected by an
 * HMAC token tied to (donor_id, type, date) and the server DB
 * credentials so donor data is never publicly exposed.
 *
 * Used by shared/CertificateImageRenderer.php to screenshot the
 * certificate for the WhatsApp PAY approval flow.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../shared/cert_token.php';

$donorId = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0;
$type = (string)($_GET['type'] ?? 'progress');
if (!in_array($type, ['progress', 'completed'], true)) {
    $type = 'progress';
}
$token = (string)($_GET['token'] ?? '');

$valid = false;
if ($donorId > 0 && $token !== '') {
    // Accept today and yesterday (render happens right after approval).
    foreach ([date('Y-m-d'), date('Y-m-d', strtotime('-1 day'))] as $day) {
        if (hash_equals(cert_render_token($donorId, $type, $day), $token)) {
            $valid = true;
            break;
        }
    }
}

if (!$valid) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$db = db();

$stmt = $db->prepare("SELECT id, name, phone, total_pledged, total_paid FROM donors WHERE id = ?");
$stmt->bind_param('i', $donorId);
$stmt->execute();
$donor = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$donor) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

// Reference: latest pledge notes containing a 4-digit code (same as get-donor-certificate-data.php)
$donor_reference = '';
$refStmt = $db->prepare("SELECT notes FROM pledges WHERE donor_id = ? ORDER BY id DESC LIMIT 1");
$refStmt->bind_param('i', $donorId);
$refStmt->execute();
$pledgeRow = $refStmt->get_result()->fetch_assoc();
$refStmt->close();
if ($pledgeRow && preg_match('/\b(\d{4})\b/', (string)($pledgeRow['notes'] ?? ''), $m)) {
    $donor_reference = $m[1];
}
if ($donor_reference === '') {
    $donor_reference = str_pad((string)$donorId, 4, '0', STR_PAD_LEFT);
}

$currency = '£';
$totalPledged = (float)($donor['total_pledged'] ?? 0);
$totalPaid = (float)($donor['total_paid'] ?? 0);
$allocationBase = max($totalPledged, $totalPaid);
$sqmValue = round($allocationBase / 400, 2);
$paymentProgress = $totalPledged > 0
    ? min(100, (int)round(($totalPaid / $totalPledged) * 100))
    : ($totalPaid > 0 ? 100 : 0);
$isFullyPaid = $totalPledged > 0 && $totalPaid >= $totalPledged;
$hasPledge = $totalPledged > 0 || $totalPaid > 0;

// A completed render for a donor that is not fully paid makes no sense.
if ($type === 'completed' && !$isFullyPaid) {
    $type = 'progress';
}

$pageW = 1200;
$pageH = $type === 'completed' ? 850 : 970;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Certificate</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
@import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@200;600;800;900&family=Noto+Sans+Ethiopic:wght@200;600;800;900&display=swap');

* { margin: 0; padding: 0; box-sizing: border-box; }
html, body {
    width: <?php echo $pageW; ?>px;
    height: <?php echo $pageH; ?>px;
    overflow: hidden;
    background: #ffffff;
}
/* ---- Progress certificate (1200x750) + stats strip (1200x220) ---- */
.donor-certificate {
    position: relative;
    width: 1200px;
    height: 750px;
    background-image: url('../assets/images/cert-bg.png');
    background-size: cover;
    background-position: center;
    background-repeat: no-repeat;
    color: white;
    font-family: 'Montserrat', sans-serif;
}

.cert-church-overlay {
    position: absolute;
    top: 0;
    right: 0;
    width: 500px;
    height: 100%;
    pointer-events: none;
    overflow: hidden;
    z-index: 0;
}

.cert-church-overlay::before {
    content: '';
    position: absolute;
    top: 50%;
    right: -50px;
    transform: translateY(-50%);
    width: 450px;
    height: 450px;
    background-image: url('../assets/images/new-church.png');
    background-size: cover;
    background-position: center;
    border-radius: 50%;
    opacity: 0.15;
    filter: saturate(0.6) brightness(1.1);
}

.cert-top-section {
    position: absolute;
    top: 25px;
    left: 0;
    right: 0;
    text-align: center;
    z-index: 1;
}

.cert-top-verse {
    color: #ffcc33;
    font-size: 41px;
    font-weight: 200;
    line-height: 1.3;
    font-family: "Nyala", "Segoe UI Ethiopic", "Noto Sans Ethiopic", serif;
    padding: 0 60px;
    text-shadow: 0 2px 4px rgba(0,0,0,0.2);
}

.cert-church-name {
    font-size: 48px;
    font-weight: 600;
    letter-spacing: 1px;
    text-transform: uppercase;
    margin-top: 15px;
    margin-bottom: 15px;
    padding-top: 10px;
    padding-bottom: 10px;
}

.cert-center-section {
    position: absolute;
    top: 200px;
    left: 0;
    right: 0;
    text-align: center;
    z-index: 1;
}

.cert-title-am {
    font-size: 135px;
    font-weight: 900;
    line-height: 1;
    font-family: "Nyala", "Segoe UI Ethiopic", "Noto Sans Ethiopic", sans-serif;
    text-shadow: 0 5px 15px rgba(0,0,0,0.2);
    margin-bottom: 10px;
    padding-top: 45px;
}

.cert-title-en {
    font-size: 120px;
    font-weight: 900;
    line-height: 1;
    letter-spacing: -3px;
    margin-top: 5px;
    margin-bottom: 10px;
    text-shadow: 0 5px 15px rgba(0,0,0,0.2);
}

.cert-bottom-section {
    position: absolute;
    bottom: 40px;
    left: 50px;
    right: 50px;
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    z-index: 1;
}

.cert-bank-area {
    display: flex;
    align-items: center;
    gap: 30px;
}

.cert-qr-code {
    width: 160px;
    height: 160px;
    background: white;
    padding: 10px;
    flex-shrink: 0;
}

.cert-qr-code img {
    width: 100%;
    height: 100%;
    display: block;
}

.cert-bank-details {
    font-size: 38px;
    font-weight: 800;
    line-height: 1.3;
    max-width: 650px;
}

.cert-bank-row {
    display: flex;
    gap: 15px;
}

.cert-bank-label {
    color: #fff;
    white-space: nowrap;
}

.cert-bank-val {
    color: #ffcc33;
    white-space: normal;
    word-break: break-word;
}

.cert-right-area {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 15px;
}

.cert-pill-box {
    width: 280px;
    height: 100px;
    background: #ffffff;
    border-radius: 50px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
    display: flex;
    align-items: center;
    justify-content: center;
}

.cert-sqm-value {
    font-size: 48px;
    font-weight: 900;
    color: #333;
    text-shadow: none;
}

.cert-reference-number {
    font-size: 20px;
    font-weight: 600;
    color: #fff;
    margin-top: 8px;
    text-align: right;
    letter-spacing: 2px;
    font-family: 'Courier New', monospace;
}

.cert-stats-strip {
    width: 1200px;
    height: 220px;
    background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
    padding: 0;
    box-sizing: border-box;
    border-top: 5px solid #e2ca18;
    font-family: 'Montserrat', sans-serif;
}

.cert-stats-row {
    display: flex;
    justify-content: stretch;
    align-items: stretch;
    height: 130px;
}

.cert-stats-row.cert-has-progress {
    height: 110px;
}

.cert-stat-item {
    text-align: center;
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 12px 10px;
}

.cert-stat-label {
    font-size: 18px;
    font-weight: 700;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 3px;
    margin-bottom: 6px;
}

.cert-stat-value {
    font-size: 38px;
    font-weight: 900;
    color: #1e293b;
    line-height: 1.1;
}

.cert-stat-value.cert-val-pledged { color: #0a6286; }
.cert-stat-value.cert-val-paid-full { color: #059669; }
.cert-stat-value.cert-val-paid-partial { color: #d97706; }
.cert-stat-value.cert-val-area { color: #059669; }
.cert-stat-value.cert-val-ref {
    font-family: 'Courier New', monospace;
    letter-spacing: 3px;
    font-size: 34px;
    color: #0a6286;
}

.cert-stat-divider {
    width: 2px;
    align-self: center;
    height: 60px;
    background: linear-gradient(180deg, transparent, #cbd5e1, transparent);
    flex-shrink: 0;
}

.cert-progress-wrap {
    padding: 12px 50px 18px;
}

.cert-progress-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.cert-progress-label {
    font-size: 18px;
    font-weight: 800;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 2px;
}

.cert-progress-pct {
    font-size: 22px;
    font-weight: 900;
    color: #1e293b;
}

.cert-progress-bar {
    width: 100%;
    height: 36px;
    background: #e2e8f0;
    border-radius: 18px;
    overflow: hidden;
    box-shadow: inset 0 3px 6px rgba(0,0,0,0.08);
    position: relative;
}

.cert-progress-fill {
    height: 100%;
    border-radius: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    min-width: 80px;
    position: relative;
}

.cert-progress-fill::after {
    content: attr(data-pct);
    font-size: 18px;
    font-weight: 900;
    color: #fff;
    text-shadow: 0 1px 3px rgba(0,0,0,0.3);
    position: absolute;
    right: 16px;
}

.cert-progress-fill.cert-fill-full {
    background: linear-gradient(90deg, #10b981, #059669, #047857);
    box-shadow: 0 3px 12px rgba(16, 185, 129, 0.4);
}

.cert-progress-fill.cert-fill-partial {
    background: linear-gradient(90deg, #fbbf24, #e2ca18, #d4a000);
    box-shadow: 0 3px 12px rgba(226, 202, 24, 0.4);
}
/* ---- Completed certificate (1200x850) - Premium Gold ---- */
.fc-certificate {
    position: relative;
    width: 1200px;
    height: 850px;
    font-family: 'Montserrat', sans-serif;
    overflow: hidden;
    color: #fff;
    background:
        linear-gradient(160deg,
            rgba(12, 12, 40, 0.88) 0%,
            rgba(18, 28, 58, 0.82) 30%,
            rgba(14, 60, 95, 0.78) 55%,
            rgba(18, 28, 58, 0.82) 75%,
            rgba(12, 12, 40, 0.88) 100%
        ),
        url('../assets/images/new-church.png');
    background-size: cover, cover;
    background-position: center, center;
}

.fc-corner {
    position: absolute;
    width: 100px;
    height: 100px;
    z-index: 2;
}

.fc-corner::before,
.fc-corner::after {
    content: '';
    position: absolute;
    background: linear-gradient(135deg, #ffd700, #d4af37);
}

.fc-corner-tl { top: 25px; left: 25px; }
.fc-corner-tl::before { top: 0; left: 0; width: 60px; height: 3px; }
.fc-corner-tl::after { top: 0; left: 0; width: 3px; height: 60px; }

.fc-corner-tr { top: 25px; right: 25px; }
.fc-corner-tr::before { top: 0; right: 0; width: 60px; height: 3px; }
.fc-corner-tr::after { top: 0; right: 0; width: 3px; height: 60px; }

.fc-corner-bl { bottom: 25px; left: 25px; }
.fc-corner-bl::before { bottom: 0; left: 0; width: 60px; height: 3px; }
.fc-corner-bl::after { bottom: 0; left: 0; width: 3px; height: 60px; }

.fc-corner-br { bottom: 25px; right: 25px; }
.fc-corner-br::before { bottom: 0; right: 0; width: 60px; height: 3px; }
.fc-corner-br::after { bottom: 0; right: 0; width: 3px; height: 60px; }

.fc-border-frame {
    position: absolute;
    top: 18px;
    left: 18px;
    right: 18px;
    bottom: 18px;
    border: 1px solid rgba(212, 175, 55, 0.25);
    border-radius: 4px;
    z-index: 1;
    pointer-events: none;
}

.fc-church-watermark {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 600px;
    height: 600px;
    opacity: 0.08;
    z-index: 0;
    pointer-events: none;
    border-radius: 50%;
    overflow: hidden;
}

.fc-church-watermark img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    filter: grayscale(30%) brightness(1.2);
}

.fc-gold-line {
    width: 180px;
    height: 2px;
    background: linear-gradient(90deg, transparent, #ffd700, #d4af37, #ffd700, transparent);
    margin: 0 auto;
}

.fc-gold-line-wide {
    width: 400px;
}

.fc-top {
    position: absolute;
    top: 40px;
    left: 0;
    right: 0;
    text-align: center;
    z-index: 3;
}

.fc-verse {
    font-size: 30px;
    font-weight: 200;
    line-height: 1.4;
    color: #ffd700;
    font-family: "Nyala", "Segoe UI Ethiopic", "Noto Sans Ethiopic", serif;
    padding: 0 80px;
    text-shadow: 0 2px 8px rgba(0,0,0,0.4);
    margin-bottom: 12px;
}

.fc-church {
    font-size: 28px;
    font-weight: 600;
    letter-spacing: 6px;
    text-transform: uppercase;
    color: rgba(255,255,255,0.85);
    text-shadow: 0 2px 6px rgba(0,0,0,0.3);
}

.fc-center {
    position: absolute;
    top: 200px;
    left: 0;
    right: 0;
    text-align: center;
    z-index: 3;
}

.fc-cert-label {
    font-size: 16px;
    font-weight: 700;
    letter-spacing: 8px;
    text-transform: uppercase;
    color: #d4af37;
    margin-bottom: 8px;
}

.fc-title-am {
    font-size: 90px;
    font-weight: 900;
    line-height: 1;
    font-family: "Nyala", "Segoe UI Ethiopic", "Noto Sans Ethiopic", sans-serif;
    color: #ffd700;
    text-shadow: 0 2px 6px rgba(0,0,0,0.35), 0 0 40px rgba(212, 175, 55, 0.15);
    margin-bottom: 6px;
}

.fc-title-en {
    font-size: 78px;
    font-weight: 900;
    line-height: 1;
    letter-spacing: -2px;
    color: #ffd700;
    text-shadow: 0 2px 6px rgba(0,0,0,0.35), 0 0 40px rgba(212, 175, 55, 0.15);
    margin-bottom: 16px;
}

.fc-subtitle {
    font-size: 17px;
    font-weight: 300;
    color: rgba(255,255,255,0.7);
    letter-spacing: 3px;
    font-style: italic;
}

.fc-donor-section {
    position: absolute;
    top: 475px;
    left: 0;
    right: 0;
    text-align: center;
    z-index: 3;
}

.fc-presented-to {
    font-size: 15px;
    font-weight: 600;
    letter-spacing: 5px;
    text-transform: uppercase;
    color: rgba(255,255,255,0.5);
    margin-bottom: 10px;
}

.fc-donor-name {
    font-size: 52px;
    font-weight: 800;
    color: #fff;
    text-shadow: 0 3px 12px rgba(0,0,0,0.3);
    margin-bottom: 14px;
    padding: 0 60px;
    line-height: 1.2;
}

.fc-bottom {
    position: absolute;
    bottom: 40px;
    left: 50px;
    right: 50px;
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    z-index: 3;
}

.fc-bottom-left {
    display: flex;
    align-items: center;
    gap: 25px;
}

.fc-qr {
    width: 120px;
    height: 120px;
    background: #fff;
    padding: 8px;
    border-radius: 8px;
    flex-shrink: 0;
    box-shadow: 0 4px 15px rgba(0,0,0,0.3);
}

.fc-qr img {
    width: 100%;
    height: 100%;
    display: block;
}

.fc-bottom-details {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.fc-detail-row {
    display: flex;
    align-items: center;
    gap: 14px;
}

.fc-detail-icon {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    background: rgba(212, 175, 55, 0.2);
    border: 1px solid rgba(212, 175, 55, 0.3);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffd700;
    font-size: 16px;
    flex-shrink: 0;
}

.fc-detail-text {
    font-size: 22px;
    font-weight: 700;
    color: #fff;
    white-space: nowrap;
}

.fc-detail-label {
    margin-right: 8px;
}

.fc-detail-text .fc-detail-highlight {
    color: #ffd700;
    margin-left: 6px;
}

.fc-bottom-right {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
}

.fc-seal {
    width: 140px;
    height: 140px;
    border-radius: 50%;
    background: linear-gradient(135deg, #ffd700, #d4af37, #b8860b);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    box-shadow:
        0 0 0 4px rgba(255, 215, 0, 0.3),
        0 0 0 8px rgba(212, 175, 55, 0.15),
        0 6px 25px rgba(0,0,0,0.4);
    position: relative;
}

.fc-seal-sqm {
    font-size: 28px;
    font-weight: 900;
    color: #1a1a2e;
    line-height: 1;
    max-width: 110px;
    text-align: center;
    word-break: break-all;
    overflow-wrap: break-word;
}

.fc-seal-label {
    font-size: 11px;
    font-weight: 800;
    color: #1a1a2e;
    text-transform: uppercase;
    letter-spacing: 2px;
    margin-top: 2px;
}

.fc-fully-paid-ribbon {
    display: flex;
    align-items: center;
    gap: 6px;
    background: linear-gradient(135deg, #059669, #047857);
    color: #fff;
    padding: 8px 20px;
    border-radius: 20px;
    font-size: 14px;
    font-weight: 800;
    letter-spacing: 2px;
    text-transform: uppercase;
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.4);
}

.fc-ref {
    font-size: 16px;
    font-weight: 600;
    font-family: 'Courier New', monospace;
    color: rgba(255,255,255,0.6);
    letter-spacing: 3px;
}

.fc-date-line {
    position: absolute;
    bottom: 195px;
    left: 0;
    right: 0;
    text-align: center;
    z-index: 3;
}

.fc-date {
    font-size: 15px;
    font-weight: 500;
    color: rgba(255,255,255,0.45);
    letter-spacing: 2px;
}
</style>
</head>
<body>
<?php if ($type === 'progress'): ?>
<div class="cert-capture-wrapper" id="donor-certificate">
    <div class="donor-certificate">
        <div class="cert-church-overlay"></div>
        <div class="cert-top-section">
            <div class="cert-top-verse">
                "የምሠራውም ቤት እጅግ ታላቅና ድንቅ ይሆናልና ብዙ እንጨት ያዘጋጁልኝ ዘንድ እነሆ ባሪያዎቼ ከባሪያዎችህ ጋር ይሆናሉ፡፡" ፪ ዜና ፪፡፱
            </div>
            <div class="cert-church-name">LIVERPOOL ABUNE TEKLEHAYMANOT EOTC</div>
        </div>
        <div class="cert-center-section">
            <div class="cert-title-am">ይህ ታሪኬ ነው</div>
            <div class="cert-title-en">It is My History</div>
        </div>
        <div class="cert-bottom-section">
            <div class="cert-bank-area">
                <div class="cert-qr-code">
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&amp;data=http://donate.abuneteklehaymanot.org/" alt="QR">
                </div>
                <div class="cert-bank-details">
                    <div class="cert-bank-row">
                        <span class="cert-bank-label">Name -</span>
                        <span class="cert-bank-val"><?php echo htmlspecialchars((string)$donor['name']); ?></span>
                    </div>
                    <div class="cert-bank-row" style="margin-top: 15px;">
                        <span class="cert-bank-label">Pledge -</span>
                        <span class="cert-bank-val"><?php echo $currency . number_format($allocationBase, 2); ?></span>
                    </div>
                </div>
            </div>
            <div class="cert-right-area">
                <div class="cert-pill-box">
                    <span class="cert-sqm-value"><?php echo $sqmValue; ?>m²</span>
                </div>
                <?php if ($donor_reference): ?>
                <div class="cert-reference-number"><?php echo htmlspecialchars($donor_reference); ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="cert-stats-strip">
        <div class="cert-stats-row <?php echo $hasPledge ? 'cert-has-progress' : ''; ?>">
            <div class="cert-stat-item">
                <div class="cert-stat-label">Ref</div>
                <div class="cert-stat-value cert-val-ref"><?php echo htmlspecialchars($donor_reference); ?></div>
            </div>
            <div class="cert-stat-divider"></div>
            <div class="cert-stat-item">
                <div class="cert-stat-label">Pledged</div>
                <div class="cert-stat-value cert-val-pledged"><?php echo $currency . number_format($totalPledged, 0); ?></div>
            </div>
            <div class="cert-stat-divider"></div>
            <div class="cert-stat-item">
                <div class="cert-stat-label">Paid</div>
                <div class="cert-stat-value <?php echo $isFullyPaid ? 'cert-val-paid-full' : 'cert-val-paid-partial'; ?>"><?php echo $currency . number_format($totalPaid, 0); ?></div>
            </div>
            <div class="cert-stat-divider"></div>
            <div class="cert-stat-item">
                <div class="cert-stat-label">Area</div>
                <div class="cert-stat-value cert-val-area"><?php echo $sqmValue; ?> m²</div>
            </div>
        </div>
        <?php if ($hasPledge): ?>
        <div class="cert-progress-wrap">
            <div class="cert-progress-header">
                <span class="cert-progress-label">Payment Progress</span>
                <span class="cert-progress-pct"><?php echo $paymentProgress; ?>%</span>
            </div>
            <div class="cert-progress-bar">
                <div class="cert-progress-fill <?php echo $isFullyPaid ? 'cert-fill-full' : 'cert-fill-partial'; ?>" style="width: <?php echo $paymentProgress; ?>%" data-pct="<?php echo $paymentProgress; ?>%"></div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>
<div class="fc-capture-wrapper" id="completed-certificate">
    <div class="fc-certificate">
        <div class="fc-corner fc-corner-tl"></div>
        <div class="fc-corner fc-corner-tr"></div>
        <div class="fc-corner fc-corner-bl"></div>
        <div class="fc-corner fc-corner-br"></div>
        <div class="fc-border-frame"></div>
        <div class="fc-church-watermark">
            <img src="../assets/images/new-church.png" alt="">
        </div>

        <div class="fc-top">
            <div class="fc-verse">
                "የምሠራውም ቤት እጅግ ታላቅና ድንቅ ይሆናልና ብዙ እንጨት ያዘጋጁልኝ ዘንድ እነሆ ባሪያዎቼ ከባሪያዎችህ ጋር ይሆናሉ፡፡" ፪ ዜና ፪፡፱
            </div>
            <div class="fc-gold-line" style="margin-bottom: 12px;"></div>
            <div class="fc-church">Liverpool Abune Teklehaymanot EOTC</div>
        </div>

        <div class="fc-center">
            <div class="fc-cert-label">Certificate of Appreciation</div>
            <div class="fc-title-am">ይህ ታሪኬ ነው</div>
            <div class="fc-title-en">It is My History</div>
            <div class="fc-gold-line fc-gold-line-wide" style="margin-top: 10px; margin-bottom: 10px;"></div>
            <div class="fc-subtitle">— Part of this historic achievement —</div>
        </div>

        <div class="fc-donor-section">
            <div class="fc-presented-to">Presented To</div>
            <div class="fc-donor-name"><?php echo htmlspecialchars((string)$donor['name']); ?></div>
            <div class="fc-gold-line"></div>
        </div>

        <div class="fc-date-line">
            <div class="fc-date"><?php echo date('F j, Y'); ?></div>
        </div>

        <div class="fc-bottom">
            <div class="fc-bottom-left">
                <div class="fc-qr">
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=120x120&amp;data=http://donate.abuneteklehaymanot.org/" alt="QR">
                </div>
                <div class="fc-bottom-details">
                    <div class="fc-detail-row">
                        <div class="fc-detail-icon"><i class="fas fa-pound-sign"></i></div>
                        <div class="fc-detail-text">
                            <span class="fc-detail-label">Contribution:</span><span class="fc-detail-highlight"><?php echo $currency . number_format($allocationBase, 2); ?></span>
                        </div>
                    </div>
                    <div class="fc-detail-row">
                        <div class="fc-detail-icon"><i class="fas fa-vector-square"></i></div>
                        <div class="fc-detail-text">
                            <span class="fc-detail-label">Area:</span><span class="fc-detail-highlight"><?php echo $sqmValue; ?> m²</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="fc-bottom-right">
                <div class="fc-seal">
                    <span class="fc-seal-sqm"><?php echo $sqmValue; ?>m²</span>
                    <span class="fc-seal-label">Allocated</span>
                </div>
                <div class="fc-fully-paid-ribbon">
                    <i class="fas fa-check-circle"></i> Fully Paid
                </div>
                <?php if ($donor_reference): ?>
                <div class="fc-ref"><?php echo htmlspecialchars($donor_reference); ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
</body>
</html>
