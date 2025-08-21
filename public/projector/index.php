<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';

// Fetch settings from the database
$settings_query = db()->query('SELECT projector_names_mode, refresh_seconds, target_amount, currency_code, projector_language FROM settings WHERE id=1');
$settings = $settings_query ? $settings_query->fetch_assoc() : null;

if (!$settings) {
    // Use defaults if no settings found
    $settings = [
        'projector_names_mode' => 'full',
        'refresh_seconds' => 10,
        'target_amount' => 100000,
        'currency_code' => 'GBP',
        'projector_language' => 'en' // Default language
    ];
}

$refresh = max(1, (int)$settings['refresh_seconds']);
$currency = htmlspecialchars($settings['currency_code'] ?? 'GBP', ENT_QUOTES, 'UTF-8');
$target = (float)$settings['target_amount'];
$initial_lang = htmlspecialchars($settings['projector_language'] ?? 'en', ENT_QUOTES, 'UTF-8');

// Load language files
$lang_en_path = __DIR__ . '/lang/en.json';
$lang_am_path = __DIR__ . '/lang/am.json';

$translations = [
    'en' => file_exists($lang_en_path) ? json_decode(file_get_contents($lang_en_path), true) : [],
    'am' => file_exists($lang_am_path) ? json_decode(file_get_contents($lang_am_path), true) : []
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title data-lang-key="live_fundraising_display">Live Fundraising Display</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="./assets/projector-live.css">
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="alternate icon" href="../../favicon.ico">
    <style>
        .lang-switcher { position: fixed; top: 15px; right: 80px; z-index: 1100; display: flex; gap: 5px; }
        .lang-switcher button { background: rgba(0,0,0,0.4); color: #fff; border: 1px solid rgba(255,255,255,0.2); border-radius: 5px; padding: 5px 10px; cursor: pointer; font-size: 14px; }
        .lang-switcher button.active { background: #fff; color: #000; }
    </style>
</head>
<body>
    <div class="lang-switcher">
        <button id="lang-en" data-lang="en">EN</button>
        <button id="lang-am" data-lang="am">አማ</button>
    </div>

    <button class="fullscreen-btn" id="fullscreenBtn" title="Toggle Fullscreen (F)">
        <i class="fas fa-expand"></i>
        <span class="label" data-lang-key="fullscreen">Fullscreen</span>
    </button>
    
    <header class="progress-header">
        <div class="progress-container">
            <div class="progress-info">
                <h1 class="campaign-title" data-lang-key="campaign_title">Live Fundraising Campaign</h1>
                <div class="progress-stats">
                    <span class="progress-current"><?= $currency ?> <span id="progressCurrent">0</span></span>
                    <span class="progress-separator" data-lang-key="of">of</span>
                    <span class="progress-target"><?= $currency ?> <?= number_format($target, 0) ?></span>
                </div>
            </div>
            <div class="progress-bar-container">
                <div class="progress-bar">
                    <div class="progress-fill" id="progressBar"></div>
                    <div class="progress-shimmer"></div>
                </div>
                <div class="progress-percentage" id="progressPercent">0%</div>
            </div>
        </div>
    </header>

    <main class="main-content">
        <aside class="totals-panel">
            <div class="total-card paid-card">
                <div class="card-header">
                    <i class="fas fa-check-circle"></i>
                    <h3 data-lang-key="total_paid">Total Paid</h3>
                </div>
                <div class="card-value">
                    <span class="currency"><?= $currency ?></span>
                    <span class="amount" id="paidTotal">0</span>
                </div>
            </div>

            <div class="total-card pledged-card">
                <div class="card-header">
                    <i class="fas fa-church"></i>
                    <h3 data-lang-key="total_pledged">Total Pledged</h3>
                </div>
                <div class="card-value">
                    <span class="currency"><?= $currency ?></span>
                    <span class="amount" id="pledgedTotal">0</span>
                </div>
            </div>

            <div class="total-card grand-card">
                <div class="card-header">
                    <i class="fas fa-trophy"></i>
                    <h3 data-lang-key="grand_total">Grand Total</h3>
                </div>
                <div class="card-value">
                    <span class="currency"><?= $currency ?></span>
                    <span class="amount" id="grandTotal">0</span>
                </div>
            </div>

            <div class="live-info">
                <div class="live-clock">
                    <i class="fas fa-clock"></i>
                    <span id="clock">00:00:00</span>
                </div>
                <div class="live-status">
                    <span class="status-dot"></span>
                    <span data-lang-key="live">LIVE</span>
                </div>
            </div>
        </aside>

        <section class="contributions-panel">
            <div class="contributions-list" id="contributionsList">
                <div class="loading-message">
                    <i class="fas fa-spinner fa-spin"></i>
                    <span data-lang-key="waiting_for_contributions">Waiting for contributions...</span>
                </div>
            </div>
        </section>
    </main>

    <footer class="message-footer" id="messageFooter">
        <div class="footer-content">
            <div class="ticker-content">
                <i class="fas fa-info-circle"></i>
                <span id="footerMessage" data-lang-key="loading">Loading...</span>
            </div>
        </div>
    </footer>

    <div class="effects-container" id="effectsContainer"></div>

    <div class="announcement-overlay" id="announcementOverlay">
        <div class="announcement-content">
            <div class="announcement-icon"><i class="fas fa-bullhorn"></i></div>
            <div class="announcement-text" id="announcementText"></div>
        </div>
    </div>

<script>
    const config = {
        refresh: <?= (int)$refresh ?> * 1000,
        currency: <?= json_encode($currency) ?>,
        target: <?= $target ?>,
        initialLang: <?= json_encode($initial_lang) ?>
    };

    const translations = <?= json_encode($translations) ?>;
    let currentLang = config.initialLang;

    function setLanguage(lang) {
        if (!translations[lang]) return;
        currentLang = lang;
        
        document.querySelectorAll('[data-lang-key]').forEach(el => {
            const key = el.getAttribute('data-lang-key');
            if (translations[lang][key]) {
                el.textContent = translations[lang][key];
            }
        });

        // Update title tag
        const titleKey = document.title.getAttribute('data-lang-key');
        if (titleKey && translations[lang][titleKey]) {
            document.title = translations[lang][titleKey];
        }

        // Update button active state
        document.getElementById('lang-en').classList.toggle('active', lang === 'en');
        document.getElementById('lang-am').classList.toggle('active', lang === 'am');

        // Re-render contributions with new language
        renderContributions(state.contributions);
    }

    document.getElementById('lang-en').addEventListener('click', () => setLanguage('en'));
    document.getElementById('lang-am').addEventListener('click', () => setLanguage('am'));

    // ... (rest of your existing JavaScript)

    let state = {
        paid: 0,
        pledged: 0,
        grand: 0,
        progress: 0,
        contributions: [],
        userScrolled: false,
        scrollTimeout: null,
        isUpdating: false,
        displayMode: null
    };

    function formatCurrency(amount) {
        return new Intl.NumberFormat('en-US', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 0
        }).format(amount);
    }

    function updateClock() {
        const now = new Date();
        document.getElementById('clock').textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
    }

    function animateNumber(element, start, end, duration = 1000) {
        const startTime = performance.now();
        const update = (currentTime) => {
            const elapsed = currentTime - startTime;
            const progress = Math.min(elapsed / duration, 1);
            const easeOutCubic = 1 - Math.pow(1 - progress, 3);
            const current = start + (end - start) * easeOutCubic;
            element.textContent = formatCurrency(current);
            if (progress < 1) requestAnimationFrame(update);
        };
        requestAnimationFrame(update);
    }
    
    function renderContributions(contributions) {
        const list = document.getElementById('contributionsList');
        // ... (code to build contribution items)
        // Inside the loop where you create a contribution item:
        const statusText = item.type === 'pledge' ? translations[currentLang].pledged : translations[currentLang].paid;
        // ... use statusText when creating the element
    }

    // Initialize everything on page load
    document.addEventListener('DOMContentLoaded', () => {
        setLanguage(config.initialLang);
        updateClock();
        setInterval(updateClock, 1000);
        // ... rest of your initialization code
    });

    // Make sure to replace hardcoded text inside your other functions as well,
    // for example, in the renderContributions function:
    function getStatusText(type) {
        return type === 'pledge' ? translations[currentLang].pledged : translations[currentLang].paid;
    }
    
    function getAnonymousText() {
        return translations[currentLang].anonymous;
    }

</script>
</body>
</html>