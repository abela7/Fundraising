<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../shared/url.php';
require_once __DIR__ . '/../shared/CampaignPayingLink.php';
require_once __DIR__ . '/../shared/CampaignGroupSettings.php';
require_once __DIR__ . '/../shared/CampaignPayingProgress.php';

header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');

$token = trim((string) ($_GET['t'] ?? ''));
if ($token === '') {
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (preg_match('#/paying/([A-Za-z0-9]+)/?$#', $path, $match)) {
        $token = $match[1];
    }
}
$token = CampaignPayingProgress::normalizeToken($token) ?? '';

$donor = null;
$error = 'ይህ ሊንክ አይሰራም።';
$welcomeHtml = '';
$statusHtml = '';
try {
    if ($token !== '') {
        $donor = CampaignPayingLink::donorByToken(db(), $token);
    }
} catch (Throwable $e) {
    error_log('Paying page load failed: ' . $e->getMessage());
    $donor = null;
}

if ($donor === null) {
    http_response_code(404);
} else {
    $welcomeTemplate = CampaignGroupSettings::defaultWelcomeMessage();
    $statusTemplate = CampaignGroupSettings::defaultStatusMessage();
    try {
        $settings = CampaignGroupSettings::get(db(), CampaignGroupSettings::GROUP_PAYING);
        if (trim((string) ($settings['welcome_message'] ?? '')) !== '') {
            $welcomeTemplate = (string) $settings['welcome_message'];
        }
        if (trim((string) ($settings['status_message'] ?? '')) !== '') {
            $statusTemplate = (string) $settings['status_message'];
        }
    } catch (Throwable $e) {
        error_log('Paying page text load failed: ' . $e->getMessage());
    }
    $welcomeHtml = nl2br(htmlspecialchars(
        CampaignGroupSettings::previewFromDonor($welcomeTemplate, $donor),
        ENT_QUOTES,
        'UTF-8'
    ), false);
    $statusHtml = nl2br(htmlspecialchars(
        CampaignGroupSettings::previewFromDonor($statusTemplate, $donor),
        ENT_QUOTES,
        'UTF-8'
    ), false);
}

$cssPath = url_for('paying/assets/paying.css');
$jsPath = url_for('paying/assets/paying.js');
$iconPath = url_for('assets/favicon.svg');
$pledged = $donor !== null ? CampaignPayingLink::formatMoney((float) ($donor['total_pledged'] ?? 0)) : '';
$paid = $donor !== null ? CampaignPayingLink::formatMoney((float) ($donor['total_paid'] ?? 0)) : '';
$remaining = $donor !== null ? CampaignPayingLink::formatMoney((float) ($donor['balance'] ?? 0)) : '';
$progress = CampaignPayingProgress::emptyState();
$paySync = null;
if ($donor !== null && $token !== '') {
    try {
        $progress = CampaignPayingProgress::load(db(), $token);
    } catch (Throwable $e) {
        error_log('Paying progress boot failed: ' . $e->getMessage());
    }
    $paySync = [
        'token' => $token,
        'sign' => CampaignPayingProgress::sign($token),
        'saveUrl' => url_for('paying/api/save.php'),
        'step' => $progress['step'],
        'answers' => $progress['answers'],
        'revision' => $progress['revision'],
        'steps' => CampaignPayingProgress::STEPS,
    ];
}
?>
<!DOCTYPE html>
<html lang="am">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0a6286">
    <?php include __DIR__ . '/../shared/noindex.php'; ?>
    <title>እንኳን ደህና መጡ</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo htmlspecialchars($iconPath, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Ethiopic:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($cssPath, ENT_QUOTES, 'UTF-8'); ?>?v=<?php echo (int) (@filemtime(__DIR__ . '/assets/paying.css') ?: time()); ?>">
</head>
<body>
    <main class="pay-sheet">
        <header class="pay-brand">
            <p class="pay-kicker">ሊቨርፑል መካነ ቅዱሳን</p>
            <h1>አቡነ ተክለሃይማኖት</h1>
        </header>

        <?php if ($donor === null): ?>
            <p class="pay-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php else: ?>
            <section class="pay-screen is-active" data-pay-step="welcome" id="payWelcome" aria-label="እንኳን ደህና መጡ">
                <div class="pay-card pay-welcome">
                    <div class="pay-welcome-text"><?php echo $welcomeHtml; ?></div>
                </div>
            </section>

            <section class="pay-screen" data-pay-step="status" id="payStatus" hidden aria-label="የክፍያ መረጃ">
                <div class="pay-stack">
                    <div class="pay-card pay-welcome">
                        <div class="pay-welcome-text"><?php echo $statusHtml; ?></div>
                    </div>
                    <div class="pay-card">
                        <div class="pay-row">
                            <span class="pay-label">ጠቅላላ የገቡት ቃልኪዳን መጠን</span>
                            <span class="pay-value"><?php echo htmlspecialchars($pledged, ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="pay-row">
                            <span class="pay-label">እስካሁን የከፈሉት</span>
                            <span class="pay-value pay-paid"><?php echo htmlspecialchars($paid, ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="pay-row pay-row-last">
                            <span class="pay-label">ቀሪ</span>
                            <span class="pay-value pay-remain"><?php echo htmlspecialchars($remaining, ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                    </div>
                    <div class="pay-choices" role="group" aria-label="ይህ መረጃ ትክክል ነው?">
                        <button type="button" class="pay-choice" data-pay-choice="status_correct" data-pay-value="yes">አዎ</button>
                        <button type="button" class="pay-choice pay-choice-no" data-pay-choice="status_correct" data-pay-value="no">አይደለም</button>
                    </div>
                </div>
            </section>

            <div class="pay-actions">
                <button type="button" class="pay-back" data-pay-back hidden>ተመለስ</button>
                <button type="button" class="pay-continue" data-pay-next>ቀጥል</button>
            </div>
        <?php endif; ?>
    </main>
    <?php if ($paySync !== null): ?>
        <script>
        window.PAY_SYNC = <?php echo json_encode(
            $paySync,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ); ?>;
        </script>
        <script src="<?php echo htmlspecialchars($jsPath, ENT_QUOTES, 'UTF-8'); ?>?v=<?php echo (int) (@filemtime(__DIR__ . '/assets/paying.js') ?: time()); ?>"></script>
    <?php endif; ?>
</body>
</html>
