<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../shared/url.php';
require_once __DIR__ . '/../shared/CampaignPayingLink.php';
require_once __DIR__ . '/../shared/CampaignGroupSettings.php';

header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');

$token = trim((string) ($_GET['t'] ?? ''));
if ($token === '') {
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (preg_match('#/paying/([A-Za-z0-9]+)/?$#', $path, $match)) {
        $token = $match[1];
    }
}

$donor = null;
$error = 'ይህ ሊንክ አይሰራም።';
$welcomeHtml = '';
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
    try {
        $settings = CampaignGroupSettings::get(db(), CampaignGroupSettings::GROUP_PAYING);
        if (trim((string) ($settings['welcome_message'] ?? '')) !== '') {
            $welcomeTemplate = (string) $settings['welcome_message'];
        }
    } catch (Throwable $e) {
        error_log('Paying welcome load failed: ' . $e->getMessage());
    }
    $welcomeText = CampaignGroupSettings::preview($welcomeTemplate, [
        'name' => trim((string) ($donor['name'] ?? '')) !== ''
            ? (string) $donor['name']
            : 'ጓደኛችን',
        'pledged' => (float) ($donor['total_pledged'] ?? 0),
        'paid' => (float) ($donor['total_paid'] ?? 0),
        'balance' => (float) ($donor['balance'] ?? 0),
    ]);
    $welcomeHtml = nl2br(htmlspecialchars($welcomeText, ENT_QUOTES, 'UTF-8'), false);
}

$cssPath = url_for('paying/assets/paying.css');
$jsPath = url_for('paying/assets/paying.js');
$iconPath = url_for('assets/favicon.svg');
$name = $donor !== null ? (string) ($donor['name'] ?? '') : '';
$pledged = $donor !== null ? CampaignPayingLink::formatMoney((float) ($donor['total_pledged'] ?? 0)) : '';
$paid = $donor !== null ? CampaignPayingLink::formatMoney((float) ($donor['total_paid'] ?? 0)) : '';
$remaining = $donor !== null ? CampaignPayingLink::formatMoney((float) ($donor['balance'] ?? 0)) : '';
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
            <section class="pay-screen is-active" id="payWelcome" aria-label="እንኳን ደህና መጡ">
                <div class="pay-card pay-welcome">
                    <div class="pay-welcome-text"><?php echo $welcomeHtml; ?></div>
                </div>
                <button type="button" class="pay-continue" id="payContinue">ቀጥል</button>
            </section>

            <section class="pay-screen" id="payInfo" hidden aria-label="የክፍያ መረጃ">
                <div class="pay-card">
                    <div class="pay-row">
                        <span class="pay-label">ስም</span>
                        <span class="pay-value pay-name"><?php echo htmlspecialchars($name !== '' ? $name : '—', ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
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
            </section>
        <?php endif; ?>
    </main>
    <?php if ($donor !== null): ?>
        <script src="<?php echo htmlspecialchars($jsPath, ENT_QUOTES, 'UTF-8'); ?>?v=<?php echo (int) (@filemtime(__DIR__ . '/assets/paying.js') ?: time()); ?>"></script>
    <?php endif; ?>
</body>
</html>
