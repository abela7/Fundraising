<?php
require_once '../../config/db.php';
require_once '../../config/env.php';

// Fetch settings from the database
$settings_query = "SELECT projector_language, refresh_rate, target_amount, currency, campaign_title FROM settings WHERE id = 1";
$settings_stmt = $pdo->query($settings_query);
$settings = $settings_stmt->fetch(PDO::FETCH_ASSOC);

$projector_language = $settings['projector_language'] ?? 'en';
$refresh_rate = $settings['refresh_rate'] ?? 5000;
$target_amount = $settings['target_amount'] ?? 100000;
$currency = $settings['currency'] ?? 'GBP';
$campaign_title = $settings['campaign_title'] ?? 'Live Fundraising Campaign';

// Load language files
$lang_en = json_decode(file_get_contents('lang/en.json'), true);
$lang_am = json_decode(file_get_contents('lang/am.json'), true);

$lang = ($projector_language === 'am') ? $lang_am : $lang_en;

// Add campaign title to language array
$lang['campaign_title'] = $campaign_title;

?>
<!DOCTYPE html>
<html lang="<?php echo $projector_language; ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $lang['live_fundraising_display']; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="assets/style.css">
    <link rel="icon" href="../../assets/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="../../favicon.ico" type="image/x-icon">
</head>

<body>
    <div class="container">
        <div class="header">
            <h1 class="campaign-title" data-lang-key="campaign_title"><?php echo $lang['campaign_title']; ?></h1>
            <div class="progress-bar-container top-progress-bar">
                <div id="progress-bar-top" class="progress-bar"></div>
                <div id="progress-percentage-top" class="progress-percentage">0%</div>
            </div>
        </div>
        <div class="main-content">
            <div class="left-panel">
                <div class="totals">
                    <div class="total-card">
                        <div class="icon"><i class="fas fa-check-circle"></i></div>
                        <div>
                            <div class="title" data-lang-key="total_paid"><?php echo $lang['total_paid']; ?></div>
                            <div class="amount" id="total-paid">--</div>
                        </div>
                    </div>
                    <div class="total-card">
                        <div class="icon"><i class="fas fa-church"></i></div>
                        <div>
                            <div class="title" data-lang-key="total_pledged"><?php echo $lang['total_pledged']; ?></div>
                            <div class="amount" id="total-pledged">--</div>
                        </div>
                    </div>
                    <div class="total-card">
                        <div class="icon"><i class="fas fa-trophy"></i></div>
                        <div>
                            <div class="title" data-lang-key="grand_total"><?php echo $lang['grand_total']; ?></div>
                            <div class="amount" id="grand-total">--</div>
                        </div>
                    </div>
                </div>
                <div class="time-and-lang">
                    <div id="live-clock" class="live-clock"></div>
                    <div class="language-switcher">
                        <button id="lang-en" class="lang-btn <?php echo ($projector_language === 'en') ? 'active' : ''; ?>" onclick="setLanguage('en')">EN</button>
                        <button id="lang-am" class="lang-btn <?php echo ($projector_language === 'am') ? 'active' : ''; ?>" onclick="setLanguage('am')">አማ</button>
                    </div>
                </div>
            </div>
            <div class="right-panel">
                <div id="live-updates" class="live-updates-container">
                    <div id="waiting-message" data-lang-key="waiting_for_contributions">
                        <?php echo $lang['waiting_for_contributions']; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="footer">
            <div class="live-update-badge">
                <span class="live-dot"></span> <span data-lang-key="live_update"><?php echo $lang['live_update']; ?></span>
            </div>
            <div id="loading-bar-text" class="loading-text" data-lang-key="loading">
                <?php echo $lang['loading']; ?>
            </div>
            <div id="celebration" class="celebration">🎉 Target Reached! 🎉</div>
        </div>
    </div>

    <script>
        const config = {
            refreshRate: <?php echo $refresh_rate; ?>,
            target: <?php echo $target_amount; ?>,
            currency: '<?php echo $currency; ?>'
        };

        const translations = {
            en: <?php echo json_encode($lang_en); ?>,
            am: <?php echo json_encode($lang_am); ?>
        };
        
        // Add dynamic campaign title to translations
        translations.en.campaign_title = "<?php echo $campaign_title; ?>";
        translations.am.campaign_title = "<?php echo $campaign_title; ?>"; // You might want a different way to translate this

        let currentLanguage = '<?php echo $projector_language; ?>';

        const state = {
            totalPaid: 0,
            totalPledged: 0,
            grandTotal: 0,
            lastId: 0,
            displayedIds: new Set()
        };

        const totalPaidElement = document.getElementById('total-paid');
        const totalPledgedElement = document.getElementById('total-pledged');
        const grandTotalElement = document.getElementById('grand-total');
        const liveUpdatesContainer = document.getElementById('live-updates');
        const progressBarTop = document.getElementById('progress-bar-top');
        const progressPercentageTop = document.getElementById('progress-percentage-top');
        const loadingBarText = document.getElementById('loading-bar-text');
        const celebrationElement = document.getElementById('celebration');

        function formatCurrency(amount, currency) {
            return `${currency} ${amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
        }

        function animateNumber(element, start, end, currency) {
            const duration = 1000;
            const range = end - start;
            let startTime = null;

            function step(timestamp) {
                if (!startTime) startTime = timestamp;
                const progress = Math.min((timestamp - startTime) / duration, 1);
                const current = start + range * progress;
                element.textContent = formatCurrency(current, currency);
                if (progress < 1) {
                    requestAnimationFrame(step);
                } else {
                    element.textContent = formatCurrency(end, currency);
                }
            }
            requestAnimationFrame(step);
        }

        function updateLiveClock() {
            const clockElement = document.getElementById('live-clock');
            const now = new Date();
            clockElement.textContent = now.toLocaleTimeString();
        }

        function updateProgressBar(current, target) {
            const percentage = target > 0 ? Math.min((current / target) * 100, 100) : 0;
            const percentageFloored = Math.floor(percentage);

            progressBarTop.style.width = `${percentage}%`;
            progressPercentageTop.textContent = `${percentageFloored}%`;

            if (percentage >= 100) {
                celebrationElement.style.display = 'block';
            } else {
                celebrationElement.style.display = 'none';
            }
        }

        function getAnonymousText() {
            return translations[currentLanguage]['anonymous_contribution'];
        }

        function getStatusText(status) {
            const key = status.toLowerCase();
            return translations[currentLanguage][key] || status;
        }

        function createCard(name, amount, status, statusText, item) {
            const card = document.createElement('div');
            card.className = `live-update-card ${status}`;
            
            const nameElement = document.createElement('div');
            nameElement.className = 'name';
            if (!item.name) {
                nameElement.dataset.langKey = 'anonymous_contribution';
            }
            nameElement.textContent = name;

            const amountElement = document.createElement('div');
            amountElement.className = 'amount';
            amountElement.textContent = formatCurrency(parseFloat(amount), config.currency);

            const cardContent = document.createElement('div');
            cardContent.className = 'card-content';
            cardContent.appendChild(nameElement);
            cardContent.appendChild(amountElement);

            const statusBadge = document.createElement('div');
            statusBadge.className = 'status-badge';
            statusBadge.dataset.langKey = status.toLowerCase();
            statusBadge.textContent = statusText;

            card.appendChild(cardContent);
            card.appendChild(statusBadge);

            return card;
        }

        function flashCard(card) {
            card.classList.add('flash');
            setTimeout(() => card.classList.remove('flash'), 1000);
        }

        function updateTotals(paid, pledged, grand) {
            const currentPaid = parseFloat(paid);
            const currentPledged = parseFloat(pledged);
            const currentGrand = parseFloat(grand);

            animateNumber(totalPaidElement, state.totalPaid, currentPaid, config.currency);
            animateNumber(totalPledgedElement, state.totalPledged, currentPledged, config.currency);
            animateNumber(grandTotalElement, state.grandTotal, currentGrand, config.currency);

            state.totalPaid = currentPaid;
            state.totalPledged = currentPledged;
            state.grandTotal = currentGrand;

            updateProgressBar(currentGrand, config.target);
        }

        async function fetchData() {
            try {
                // Fetch totals
                const totalsResponse = await fetch('../../api/totals.php');
                const totals = await totalsResponse.json();
                updateTotals(totals.total_paid, totals.total_pledged, totals.grand_total);

                // Fetch recent contributions
                const response = await fetch(`../../api/recent.php?since=${state.lastId}`);
                const result = await response.json();

                if (loadingBarText.style.display !== 'none') {
                    loadingBarText.style.display = 'none';
                }

                if (result.length > 0) {
                    if (document.getElementById('waiting-message')) {
                        document.getElementById('waiting-message').style.display = 'none';
                    }
                    state.lastId = result[0].id;
                    result.reverse().forEach(item => {
                        const isNew = !state.displayedIds.has(item.id);
                        if (isNew) {
                            const name = item.name ? item.name : getAnonymousText();
                            const statusText = getStatusText(item.status);
                            const card = createCard(name, item.amount, item.status, statusText, item);
                            liveUpdatesContainer.prepend(card);
                            state.displayedIds.add(item.id);
                            flashCard(card);
                        }
                    });
                }
            } catch (error) {
                console.error('Error fetching data:', error);
            }
        }

        function setLanguage(lang) {
            currentLanguage = lang;
            document.documentElement.lang = lang;

            document.querySelectorAll('[data-lang-key]').forEach(element => {
                const key = element.getAttribute('data-lang-key');
                if (translations[lang] && translations[lang][key]) {
                    element.textContent = translations[lang][key];
                }
            });

            // Update dynamically created cards
            document.querySelectorAll('.live-update-card').forEach(card => {
                const nameEl = card.querySelector('.name');
                if (nameEl && nameEl.dataset.langKey === 'anonymous_contribution') {
                    nameEl.textContent = getAnonymousText();
                }

                const statusEl = card.querySelector('.status-badge');
                if (statusEl && statusEl.dataset.langKey) {
                    const statusKey = statusEl.dataset.langKey;
                    statusEl.textContent = getStatusText(statusKey);
                }
            });

            document.getElementById('lang-en').classList.toggle('active', lang === 'en');
            document.getElementById('lang-am').classList.toggle('active', lang === 'am');
        }


        document.addEventListener('DOMContentLoaded', () => {
            fetchData();
            setInterval(fetchData, config.refreshRate);
            updateLiveClock();
            setInterval(updateLiveClock, 1000);
        });
    </script>
</body>

</html>